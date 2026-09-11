<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Models\PartnerProspect;
use App\Services\EventStore;
use App\Services\Outreach\ProspectStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies one recorded Affonso event: match the affiliate to a prospect
 * (external user id = lead id, then the prospect token we put in metadata,
 * then the email), store the affiliate ids, and mark the prospect signed up.
 */
class HandleAffonsoWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId, public readonly string $receiptId) {}

    public function handle(ProspectStateMachine $lifecycle, EventStore $events): void
    {
        $receipt = DB::table('webhook_receipts')->where('id', $this->receiptId)->where('tenant_id', $this->tenantId)->first();
        if ($receipt === null || $receipt->processed_at !== null) {
            return;
        }

        $payload = json_decode((string) $receipt->payload, true) ?: [];
        $data = (array) ($payload['data'] ?? []);
        $type = (string) $receipt->event_type;

        $prospect = $this->match($data);
        if ($prospect === null) {
            Log::info('affonso.webhook.unmatched', ['tenant_id' => $this->tenantId, 'type' => $type, 'email' => $data['email'] ?? null]);
            DB::table('webhook_receipts')->where('id', $this->receiptId)->update(['processed_at' => now(), 'error' => 'unmatched']);

            return;
        }

        $status = strtoupper(trim((string) ($data['status'] ?? '')));

        DB::transaction(function () use ($prospect, $data, $type, $status, $lifecycle) {
            $locked = PartnerProspect::whereKey($prospect->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'affonso_affiliate_id' => $data['affiliateId'] ?? $locked->affonso_affiliate_id,
                'affonso_tracking_id' => $data['trackingId'] ?? $locked->affonso_tracking_id,
                'affiliate_status' => $type === 'affiliate.deleted' ? 'DELETED' : ($status !== '' ? $status : $locked->affiliate_status),
            ])->save();

            if (in_array($type, ['affiliate.created', 'affiliate.confirmed', 'affiliate.updated'], true) && ! $locked->isTerminal()) {
                $lifecycle->transition($locked, PartnerProspect::STATUS_SIGNED_UP, ['signed_up_at' => now(), 'next_send_at' => null],
                    array_values(array_diff(PartnerProspect::STATUSES, PartnerProspect::TERMINAL)));
            }
        });

        $events->append($this->tenantId, 'partner_prospect', (string) $prospect->id, 'outreach.affiliate.'.Str::after($type, 'affiliate.'), [
            'prospect_id' => $prospect->id, 'affiliate_id' => $data['affiliateId'] ?? null, 'status' => $status, 'via' => 'webhook',
        ]);

        DB::table('webhook_receipts')->where('id', $this->receiptId)->update(['processed_at' => now(), 'error' => null]);
    }

    /** @param array<string, mixed> $data */
    private function match(array $data): ?PartnerProspect
    {
        $external = trim((string) ($data['externalUserId'] ?? ''));
        if ($external !== '' && Str::isUuid($external)) {
            $prospect = PartnerProspect::forTenant($this->tenantId)->where('lead_id', $external)->first();
            if ($prospect !== null) {
                return $prospect;
            }
        }

        $token = trim((string) (((array) ($data['metadata'] ?? []))['prospect_token'] ?? ''));
        if ($token !== '') {
            $prospect = PartnerProspect::forTenant($this->tenantId)->where('invite_token', strtolower($token))->first();
            if ($prospect !== null) {
                return $prospect;
            }
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '') {
            $leadId = Lead::forTenant($this->tenantId)->where('email', $email)->value('id');
            if ($leadId) {
                return PartnerProspect::forTenant($this->tenantId)->where('lead_id', $leadId)->first();
            }
        }

        return null;
    }
}
