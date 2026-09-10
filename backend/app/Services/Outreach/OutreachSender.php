<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Mail\PartnerOutreachMail;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Services\FeatureFlag;
use App\Services\Messaging\MessageDispatchService;
use App\Services\Messaging\TenantMailerFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One tick of the outreach sequence for a tenant: queue DM drafts for
 * prospects without an email, retire prospects whose last call expired, then
 * send the due email steps inside the warm-up / hourly / gap budget and
 * outside quiet hours. Every send is claimed first so concurrent ticks never
 * double-send, and goes out AS the tenant's mailbox with threading headers.
 */
class OutreachSender
{
    public const SENT_BY = 'outreach';

    public function __construct(
        private readonly OutreachSettings $settings,
        private readonly ProspectStateMachine $lifecycle,
        private readonly OutreachMessageComposer $composer,
        private readonly MessageDispatchService $dispatch,
        private readonly TenantMailerFactory $mailers,
    ) {}

    /** Active tenants with outreach configured. @return Collection<int, Tenant> */
    public function tenants(): Collection
    {
        return Tenant::where('status', 'active')->get()
            ->filter(fn (Tenant $t) => isset(((array) $t->settings)[OutreachSettings::KEY]))
            ->values();
    }

    /**
     * @return array{tenant_id: string, skipped: ?string, drafted: int, retired: int, due: int, sent: int, failed: int, dry_run: bool}
     */
    public function runForTenant(Tenant $tenant, bool $dryRun = false, bool $force = false): array
    {
        $tenantId = (string) $tenant->id;
        $config = $this->settings->for($tenant);
        $result = ['tenant_id' => $tenantId, 'skipped' => null, 'drafted' => 0, 'retired' => 0, 'due' => 0, 'sent' => 0, 'failed' => 0, 'dry_run' => $dryRun];

        if (! $force && ! FeatureFlag::on('outreach.sending', $tenantId)) {
            $result['skipped'] = 'flag_off';

            return $result;
        }

        $result['drafted'] = $this->draftDms($tenant, $dryRun);
        $result['retired'] = $this->retireDue($tenantId, $dryRun);

        $result['due'] = $this->dueQuery($tenantId)->count();
        if ($result['due'] === 0) {
            return $result;
        }

        $sending = (array) $config['sending'];
        $tz = (string) ($sending['timezone'] ?? 'UTC');

        if (! $this->mailers->hasSmtp($this->mailers->credentialsFor($tenantId))) {
            $result['skipped'] = 'no_mailbox';

            return $result;
        }

        if ($this->inQuietHours((array) ($sending['quiet_hours'] ?? []), $tz)) {
            $result['skipped'] = 'quiet_hours';

            return $result;
        }

        $budget = $this->budget($tenantId, $sending, $tz);
        if ($budget['allowed'] <= 0) {
            $result['skipped'] = $budget['reason'];

            return $result;
        }

        $batch = $this->dueQuery($tenantId)->orderBy('next_send_at')->limit($budget['allowed'])->get();

        foreach ($batch as $prospect) {
            if ($dryRun) {
                $result['sent']++;

                continue;
            }

            $token = $this->lifecycle->claimForSend($prospect);
            if ($token === null) {
                continue;
            }

            try {
                $outcome = $this->sendStep($tenant, $prospect->refresh(), $config);
            } finally {
                $this->lifecycle->releaseClaim($prospect, $token);
            }

            if ($outcome === 'sent') {
                $result['sent']++;
                if (empty($sending['started_at'])) {
                    // Warm-up clock starts with the first real send.
                    $config = $this->settings->update($tenant, ['sending' => ['started_at' => now()->toIso8601String()]]);
                    $sending = (array) $config['sending'];
                }
            } elseif ($outcome === 'failed') {
                $result['failed']++;
            }
        }

        return $result;
    }

    private function dueQuery(string $tenantId)
    {
        return PartnerProspect::forTenant($tenantId)
            ->whereIn('status', PartnerProspect::SENDABLE)
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(10));
            });
    }

    /** @return 'sent'|'failed'|'skipped' */
    private function sendStep(Tenant $tenant, PartnerProspect $prospect, array $config): string
    {
        $steps = array_values((array) $config['sequence']);
        $index = (int) $prospect->sequence_step; // steps already sent
        $step = $steps[$index] ?? null;
        $retireAfter = max(1, (int) (($config['sending']['retire_after_days'] ?? null) ?: 10));

        if ($step === null) {
            $this->lifecycle->transition($prospect, PartnerProspect::STATUS_LAST_CALLED, ['next_send_at' => now()->addDays($retireAfter)], PartnerProspect::SENDABLE);

            return 'skipped';
        }

        $lead = $prospect->lead;
        if ($lead === null || empty($lead->email)) {
            $this->lifecycle->transition($prospect, PartnerProspect::STATUS_NEEDS_EMAIL, ['next_send_at' => null], PartnerProspect::SENDABLE);

            return 'skipped';
        }

        $composed = $this->composer->compose($tenant, $prospect, (string) $step['template'], 'email');
        $sender = $this->mailers->senderFor($this->mailers->credentialsFor((string) $tenant->id));
        $program = (array) $config['program'];
        $domain = Str::after($sender['address'], '@') ?: 'outreach.local';
        $messageId = Str::uuid().'.'.$prospect->invite_token.'@'.$domain;
        [$inReplyTo, $references] = $this->threading($prospect);

        $mailable = new PartnerOutreachMail(
            subjectLine: (string) ($composed['subject'] ?? 'Partnering with '.($program['brand'] ?? 'us')),
            bodyText: $composed['body'],
            sender: $sender,
            replyToAddress: Str::before($sender['address'], '@').'+'.$prospect->invite_token.'@'.$domain,
            messageId: $messageId,
            inReplyTo: $inReplyTo,
            references: $references,
            footer: [
                'legal_name' => (string) ($program['operator_legal_name'] ?? ''),
                'postal_address' => $program['postal_address'] ?? null,
                'reason' => 'You are receiving this because you publicly create content for people who run marketing; we contact creators a few times at most about partnerships (legitimate interest). Reply STOP or use the link below to never hear from us again.',
                'unsubscribe_url' => $this->composer->unsubscribeUrl($prospect),
            ],
        );

        $result = $this->dispatch->send($lead, 'email', $composed['body'], $composed['subject'], $composed['template_key'], self::SENT_BY, [
            'mailable' => $mailable,
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'require_tenant_mailer' => true,
        ]);

        if (! $result['success']) {
            PartnerProspect::whereKey($prospect->id)->update(['next_send_at' => now()->addMinutes(15)]);
            Log::warning('outreach send failed', ['prospect_id' => $prospect->id, 'error' => $result['error'] ?? null]);

            return 'failed';
        }

        $stepNumber = $index + 1;
        $isLast = ! isset($steps[$index + 1]);
        $status = $stepNumber === 1
            ? PartnerProspect::STATUS_INVITED
            : ($isLast ? PartnerProspect::STATUS_LAST_CALLED : PartnerProspect::STATUS_NUDGED);
        $nextAt = $isLast
            ? now()->addDays($retireAfter)
            : now()->addDays(max(1, (int) ($steps[$index + 1]['wait_days'] ?? 3)));

        $this->lifecycle->transition($prospect, $status, [
            'sequence_step' => $stepNumber, 'last_sent_at' => now(), 'next_send_at' => $nextAt,
        ], PartnerProspect::SENDABLE);

        return 'sent';
    }

    /** @return array{0: ?string, 1: list<string>} In-Reply-To + References from our earlier emails in the thread */
    private function threading(PartnerProspect $prospect): array
    {
        $conversation = Conversation::forTenant((string) $prospect->tenant_id)
            ->where('lead_id', $prospect->lead_id)->where('channel', 'email')->first();
        if ($conversation === null) {
            return [null, []];
        }

        $ids = ConversationMessage::where('conversation_id', $conversation->id)
            ->where('direction', 'out')->whereNotNull('message_id_header')
            ->orderBy('created_at')->pluck('message_id_header')->all();
        $ids = array_slice(array_values($ids), -5);

        return [$ids === [] ? null : (string) end($ids), $ids];
    }

    private function draftDms(Tenant $tenant, bool $dryRun): int
    {
        $candidates = PartnerProspect::forTenant((string) $tenant->id)
            ->where('status', PartnerProspect::STATUS_NEEDS_EMAIL)->whereNull('dm_draft_at')
            ->orderBy('created_at')->limit(50)->get();
        $count = 0;

        foreach ($candidates as $prospect) {
            if ($dryRun) {
                $count++;

                continue;
            }
            // Claim the draft slot first so two ticks cannot queue two drafts.
            if (PartnerProspect::whereKey($prospect->id)->whereNull('dm_draft_at')->update(['dm_draft_at' => now()]) !== 1) {
                continue;
            }
            $lead = $prospect->lead;
            if ($lead === null) {
                PartnerProspect::whereKey($prospect->id)->update(['dm_draft_at' => null]);

                continue;
            }
            $composed = $this->composer->compose($tenant, $prospect, 'partner.dm_invite', MessageDispatchService::MANUAL_DM);
            $sent = $this->dispatch->send($lead, MessageDispatchService::MANUAL_DM, $composed['body'], null, $composed['template_key'], self::SENT_BY);

            if ($sent['success'] && $this->lifecycle->transition($prospect, PartnerProspect::STATUS_DM_DRAFTED, [], [PartnerProspect::STATUS_NEEDS_EMAIL])) {
                $count++;
            } else {
                PartnerProspect::whereKey($prospect->id)->update(['dm_draft_at' => null]);
            }
        }

        return $count;
    }

    private function retireDue(string $tenantId, bool $dryRun): int
    {
        $due = PartnerProspect::forTenant($tenantId)->where('status', PartnerProspect::STATUS_LAST_CALLED)
            ->whereNotNull('next_send_at')->where('next_send_at', '<=', now())->get();
        $count = 0;
        foreach ($due as $prospect) {
            if ($dryRun || $this->lifecycle->transition($prospect, PartnerProspect::STATUS_RETIRED, ['next_send_at' => null], [PartnerProspect::STATUS_LAST_CALLED])) {
                $count++;
            }
        }

        return $count;
    }

    /** @return array{allowed: int, reason: ?string} */
    private function budget(string $tenantId, array $sending, string $tz): array
    {
        $base = PartnerProspect::forTenant($tenantId);
        $perRun = max(1, (int) ($sending['per_run_cap'] ?? 5));
        $dayCap = $this->dailyCap($sending);
        $sentToday = (clone $base)->where('last_sent_at', '>=', now($tz)->startOfDay()->utc())->count();
        if ($sentToday >= $dayCap) {
            return ['allowed' => 0, 'reason' => 'daily_cap'];
        }
        $hourCap = max(1, (int) ($sending['hourly_burst_cap'] ?? 40));
        $sentLastHour = (clone $base)->where('last_sent_at', '>=', now()->subHour())->count();
        if ($sentLastHour >= $hourCap) {
            return ['allowed' => 0, 'reason' => 'hourly_cap'];
        }
        $gap = max(0, (int) ($sending['min_gap_seconds'] ?? 0));
        $allowed = min($perRun, $dayCap - $sentToday, $hourCap - $sentLastHour);
        if ($gap > 0) {
            $last = (clone $base)->max('last_sent_at');
            if ($last !== null && Carbon::parse($last)->gt(now()->subSeconds($gap))) {
                return ['allowed' => 0, 'reason' => 'min_gap'];
            }
            $allowed = min($allowed, 1); // one per tick keeps the spacing honest
        }

        return ['allowed' => $allowed, 'reason' => null];
    }

    /** Warm-up ladder: the cap of the highest from_day reached since the first send. */
    public function dailyCap(array $sending): int
    {
        $ladder = (array) ($sending['warmup'] ?? []);
        $startedAt = ! empty($sending['started_at']) ? Carbon::parse((string) $sending['started_at']) : null;
        $day = $startedAt ? (int) floor($startedAt->diffInDays(now(), false)) + 1 : 1;
        $cap = max(1, (int) ($sending['per_run_cap'] ?? 5));
        usort($ladder, fn ($a, $b) => ((int) ($a['from_day'] ?? 0)) <=> ((int) ($b['from_day'] ?? 0)));
        foreach ($ladder as $rung) {
            if ((int) ($rung['from_day'] ?? 0) <= $day) {
                $cap = max(1, (int) ($rung['cap'] ?? $cap));
            }
        }

        return $cap;
    }

    private function inQuietHours(array $quietHours, string $tz): bool
    {
        $start = (string) ($quietHours['start'] ?? '');
        $end = (string) ($quietHours['end'] ?? '');
        if ($start === '' || $end === '' || $start === $end) {
            return false;
        }
        $now = now($tz)->format('H:i');

        return $start > $end ? ($now >= $start || $now < $end) : ($now >= $start && $now < $end);
    }
}
