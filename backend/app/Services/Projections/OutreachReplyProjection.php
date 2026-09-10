<?php

declare(strict_types=1);

namespace App\Services\Projections;

use App\Jobs\DraftPartnerReplyJob;
use App\Models\Event;
use App\Models\PartnerProspect;
use App\Services\FeatureFlag;
use Illuminate\Support\Facades\Log;

/**
 * The Laravel half of the reply loop: a creator's inbound email
 * (conversation.message.received tagged bridge=laravel_outreach) queues one
 * recruiter-bot draft. The claim on reply_claim_message_id makes a replayed
 * event a no-op, and the flag keeps the bot dark until switched on per tenant.
 */
class OutreachReplyProjection
{
    public function accepts(Event $event): bool
    {
        if ($event->event_type !== 'conversation.message.received') {
            return false;
        }
        $payload = $event->payload;

        return ($payload['bridge'] ?? null) === 'laravel_outreach' && ! empty($payload['prospect_id']) && ! empty($payload['message_id']);
    }

    public function handle(Event $event): void
    {
        try {
            $tenantId = (string) $event->tenant_id;
            $payload = $event->payload;

            if (! FeatureFlag::on('outreach.bot_replies', $tenantId)) {
                return;
            }
            if (in_array((string) ($payload['classification'] ?? 'reply'), ['opt_out', 'bounce', 'auto_reply'], true)) {
                return;
            }

            $prospectId = (string) $payload['prospect_id'];
            $messageId = (string) $payload['message_id'];

            // One draft per inbound message, whichever worker gets here first.
            $claimed = PartnerProspect::where('id', $prospectId)->where('tenant_id', $tenantId)
                ->where(function ($q) use ($messageId) {
                    $q->whereNull('reply_claim_message_id')->orWhere('reply_claim_message_id', '!=', $messageId);
                })
                ->update(['reply_claim_message_id' => $messageId]);

            if ($claimed !== 1) {
                return;
            }

            DraftPartnerReplyJob::dispatch($tenantId, $prospectId, $messageId)->onQueue('intelligence');
        } catch (\Throwable $e) {
            // Never block the event write.
            Log::warning('[OutreachReplyProjection] dispatch failed', ['event_id' => $event->id ?? null, 'error' => $e->getMessage()]);
        }
    }
}
