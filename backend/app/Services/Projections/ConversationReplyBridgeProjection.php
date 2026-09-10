<?php

declare(strict_types=1);

namespace App\Services\Projections;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Bridges inbound conversation events to the intelligence plane: on
 * `conversation.message.received`, LPUSH a dispatch job onto the Redis
 * `agent:dispatch` list so the pack's CRM agent drafts a reply
 * (intelligence/main.py consumes with BLPOP and requires tenant_id,
 * agent_id, intent and context — same envelope as MetaPlanner::dispatch()
 * and Jobs/CheckAnomaliesJob).
 *
 * Gated on the tenant's automation level: 'manual' tenants opted out of
 * agent-driven replies entirely, so no job is queued for them. Best-effort:
 * Redis being down never blocks the event write (the durable record is the
 * event_log row; a replay can re-dispatch).
 */
class ConversationReplyBridgeProjection
{
    private const QUEUE = 'agent:dispatch';
    private const AGENT_SLUG = 'sales_crm_crm';

    public function accepts(Event $event): bool
    {
        if ($event->event_type !== 'conversation.message.received') {
            return false;
        }

        // Partner-outreach conversations are answered by the Laravel recruiter
        // loop; their events carry bridge=laravel_outreach and must not be
        // double-handled by the Python CRM agent. Legacy producers (WhatsApp)
        // carry no bridge key and keep flowing.
        return ($event->payload['bridge'] ?? 'python') === 'python';
    }

    public function handle(Event $event): void
    {
        try {
            $tenantId = (string) $event->tenant_id;

            $automationLevel = (string) (DB::table('tenants')
                ->where('id', $tenantId)
                ->value('automation_level') ?? 'assisted');

            if ($automationLevel === 'manual') {
                return;
            }

            $payload = $event->payload;
            $conversationId = (string) ($payload['conversation_id'] ?? $event->aggregate_id);

            $job = json_encode([
                'id' => (string) Str::uuid(),
                'event_id' => $event->id,
                'tenant_id' => $tenantId,
                'agent_id' => self::AGENT_SLUG,
                'intent' => 'conversation_reply',
                'context' => [
                    'lead_id' => $payload['lead_id'] ?? null,
                    'conversation_id' => $conversationId,
                    'message_id' => $payload['message_id'] ?? $this->latestInboundMessageId($tenantId, $conversationId),
                    'channel' => $payload['channel'] ?? null,
                    'automation_level' => $automationLevel,
                    'requested_at' => now()->toIso8601String(),
                ],
                'priority' => 'normal',
                'version' => '3.2',
            ]);

            Redis::lpush(self::QUEUE, $job);
        } catch (\Throwable $e) {
            // Never block the event write — this projection is best-effort.
            Log::warning('[ConversationReplyBridge] dispatch failed', [
                'event_id' => $event->id ?? null,
                'tenant_id' => $event->tenant_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Producers that predate the message_id payload field (e.g. the WhatsApp
     * webhook) only carry lead_id + channel — recover the triggering inbound
     * message so the agent replies to the right thing.
     */
    private function latestInboundMessageId(string $tenantId, string $conversationId): ?string
    {
        try {
            $id = DB::table('conversation_messages')
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->where('direction', 'in')
                ->orderByDesc('created_at')
                ->value('id');

            return $id !== null ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
