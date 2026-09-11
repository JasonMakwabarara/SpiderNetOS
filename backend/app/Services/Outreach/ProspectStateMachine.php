<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\ConsentRecord;
use App\Models\Lead;
use App\Models\PartnerProspect;
use App\Services\EventStore;
use App\Services\Outreach\Enrichment\EmailValidator;
use App\Services\Sales\LeadService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The prospect lifecycle: guarded status transitions (conditional UPDATEs so
 * concurrent workers cannot double-apply), the send claim, contact acceptance
 * with consent bookkeeping, and the coarse mirror onto Lead.stage so the
 * existing pipeline views stay honest.
 */
class ProspectStateMachine
{
    /** Prospect status => Lead stage (null = leave the lead alone). */
    private const LEAD_STAGE = [
        PartnerProspect::STATUS_REPLIED => 'engaged',
        PartnerProspect::STATUS_NEGOTIATING => 'engaged',
        PartnerProspect::STATUS_HANDOFF => 'engaged',
        PartnerProspect::STATUS_SIGNED_UP => 'won',
        PartnerProspect::STATUS_DECLINED => 'lost',
        PartnerProspect::STATUS_UNSUBSCRIBED => 'lost',
        PartnerProspect::STATUS_BOUNCED => 'lost',
        PartnerProspect::STATUS_RETIRED => 'recycled',
    ];

    public function __construct(
        private readonly EventStore $events,
        private readonly LeadService $leads,
        private readonly EmailValidator $emails,
    ) {}

    /**
     * Move to $to only if the row is still in one of $from (defaults to the
     * in-memory status). Returns false when another worker got there first.
     *
     * @param  array<string, mixed>  $extra  additional columns to set atomically
     * @param  list<string>|null  $from
     */
    public function transition(PartnerProspect $prospect, string $to, array $extra = [], ?array $from = null): bool
    {
        if (! in_array($to, PartnerProspect::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown prospect status: {$to}");
        }

        $affected = PartnerProspect::whereKey($prospect->id)
            ->whereIn('status', $from ?? [$prospect->status])
            ->update(['status' => $to, 'updated_at' => now()] + $extra);

        if ($affected !== 1) {
            return false;
        }

        $from = $prospect->status;
        $prospect->refresh();
        $this->mirrorLeadStage($prospect);

        $this->events->append((string) $prospect->tenant_id, 'partner_prospect', (string) $prospect->id, 'outreach.prospect.'.$to, [
            'prospect_id' => $prospect->id, 'lead_id' => $prospect->lead_id, 'from' => $from, 'to' => $to,
        ] + array_intersect_key($extra, array_flip(['needs_human_reason', 'bounce_reason', 'sequence_step'])));

        return true;
    }

    /**
     * Accept a contact address: validate, reject addresses another lead of the
     * tenant already owns or that opted out, then record consent
     * (legitimate-interest B2B) and make the prospect sendable.
     *
     * @return array{ok: bool, email: string, reason: ?string}
     */
    public function acceptEmail(PartnerProspect $prospect, string $email, string $source): array
    {
        $check = $this->emails->check($email);
        if (! $check['ok']) {
            return $check;
        }
        $email = $check['email'];
        $tenantId = (string) $prospect->tenant_id;

        $inUse = Lead::forTenant($tenantId)->where('email', $email)->where('id', '!=', $prospect->lead_id)->exists();
        if ($inUse) {
            return ['ok' => false, 'email' => $email, 'reason' => 'email_in_use'];
        }

        if (ConsentRecord::hasOptOut($tenantId, $email, 'email')) {
            return ['ok' => false, 'email' => $email, 'reason' => 'opted_out'];
        }

        DB::transaction(function () use ($prospect, $email, $source, $tenantId) {
            $lead = $prospect->lead()->lockForUpdate()->firstOrFail();
            $consent = (array) ($lead->consent ?? []);
            $consent['email_opt_in'] = true;
            $lead->update(['email' => $email, 'consent' => $consent]);

            ConsentRecord::log($tenantId, $email, 'email', 'granted', 'legitimate_interest_b2b', [
                'prospect_id' => $prospect->id, 'email_source' => $source,
            ]);

            $prospect->forceFill(['email_source' => $source, 'email_verified_at' => now()])->save();
        });

        $this->transition($prospect, PartnerProspect::STATUS_READY, ['next_send_at' => now()], [
            PartnerProspect::STATUS_NEW, PartnerProspect::STATUS_NEEDS_EMAIL,
            PartnerProspect::STATUS_DM_DRAFTED, PartnerProspect::STATUS_DM_SENT,
        ]);

        return ['ok' => true, 'email' => $email, 'reason' => null];
    }

    /**
     * Claim a due prospect for sending. Only one worker wins; a claim older
     * than $staleMinutes (a crashed worker) may be taken over.
     */
    public function claimForSend(PartnerProspect $prospect, int $staleMinutes = 10): ?string
    {
        $token = (string) Str::uuid();

        $affected = PartnerProspect::whereKey($prospect->id)
            ->whereIn('status', PartnerProspect::SENDABLE)
            ->where('next_send_at', '<=', now())
            ->where(function ($q) use ($staleMinutes) {
                $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes($staleMinutes));
            })
            ->update(['claimed_at' => now(), 'claim_token' => $token]);

        return $affected === 1 ? $token : null;
    }

    public function releaseClaim(PartnerProspect $prospect, string $token): void
    {
        PartnerProspect::whereKey($prospect->id)->where('claim_token', $token)
            ->update(['claimed_at' => null, 'claim_token' => null]);
    }

    /** STOP / unsubscribe: consent record, lead flag, terminal status, sequence halted. */
    public function optOut(PartnerProspect $prospect, string $source, array $meta = []): void
    {
        $lead = $prospect->lead;
        if ($lead && $lead->email) {
            ConsentRecord::log((string) $prospect->tenant_id, $lead->email, 'email', 'stopped', $source, $meta + ['prospect_id' => $prospect->id]);
            $consent = (array) ($lead->consent ?? []);
            $consent['opted_out_at'] = now()->toIso8601String();
            $lead->update(['consent' => $consent]);
        }

        $this->transition($prospect, PartnerProspect::STATUS_UNSUBSCRIBED, [
            'unsubscribed_at' => now(), 'next_send_at' => null, 'claimed_at' => null, 'claim_token' => null,
        ], array_values(array_diff(PartnerProspect::STATUSES, PartnerProspect::TERMINAL)));
    }

    public function markBounced(PartnerProspect $prospect, string $reason): void
    {
        $lead = $prospect->lead;
        if ($lead && $lead->email) {
            ConsentRecord::log((string) $prospect->tenant_id, $lead->email, 'email', 'bounced', 'dsn', ['prospect_id' => $prospect->id, 'reason' => $reason]);
        }

        $this->transition($prospect, PartnerProspect::STATUS_BOUNCED, [
            'bounced_at' => now(), 'bounce_reason' => mb_substr($reason, 0, 250), 'next_send_at' => null,
        ], array_values(array_diff(PartnerProspect::STATUSES, PartnerProspect::TERMINAL)));
    }

    /** An inbound reply arrived: stop the sequence and open the conversation. */
    public function markReplied(PartnerProspect $prospect): bool
    {
        return $this->transition($prospect, PartnerProspect::STATUS_REPLIED, [
            'replied_at' => $prospect->replied_at ?? now(), 'last_inbound_at' => now(), 'next_send_at' => null,
        ], [
            PartnerProspect::STATUS_READY, PartnerProspect::STATUS_INVITED, PartnerProspect::STATUS_NUDGED,
            PartnerProspect::STATUS_LAST_CALLED, PartnerProspect::STATUS_RETIRED, PartnerProspect::STATUS_DM_DRAFTED,
            PartnerProspect::STATUS_DM_SENT, PartnerProspect::STATUS_NEEDS_EMAIL, PartnerProspect::STATUS_DECLINED,
        ]);
    }

    /**
     * Keep Lead.stage roughly in step with the prospect. Uses LeadService so
     * the STE events fire; tagged bridge=laravel_outreach so the engaged
     * transition (event type conversation.message.received) never wakes the
     * Python CRM agent. Never lets a mapping problem break outreach.
     */
    private function mirrorLeadStage(PartnerProspect $prospect): void
    {
        $target = self::LEAD_STAGE[$prospect->status] ?? null;
        $lead = $prospect->lead;

        if ($target === null || $lead === null || $lead->stage === $target || in_array($lead->stage, ['won'], true)) {
            return;
        }

        try {
            $this->leads->transitionStage($lead, $target, ['bridge' => 'laravel_outreach', 'prospect_id' => $prospect->id]);
        } catch (\Throwable $e) {
            Log::warning('outreach lead stage mirror failed', ['prospect_id' => $prospect->id, 'stage' => $target, 'error' => $e->getMessage()]);
        }
    }
}
