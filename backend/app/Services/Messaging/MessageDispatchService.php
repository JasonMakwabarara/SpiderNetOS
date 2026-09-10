<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\ConsentRecord;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Services\EventStore;
use Illuminate\Support\Facades\Log;

/**
 * Channel router for the sales-crm pack. Renders a message_templates row
 * against a lead + tenant, hard-blocks opted-out leads, sends via the
 * matching channel adapter, and logs the outbound turn to conversations.
 */
class MessageDispatchService
{
    /** Operator-assisted channel: drafts queued for a human, never sent by us. */
    public const MANUAL_DM = 'manual_dm';

    public function __construct(
        private readonly EmailChannel $emailChannel,
        private readonly WhatsAppChannel $whatsAppChannel,
        private readonly SmsChannel $smsChannel,
        private readonly EventStore $eventStore,
    ) {}

    /**
     * @return array{success: bool, message?: ConversationMessage, error?: string}
     */
    public function sendTemplate(Lead $lead, string $channel, string $templateKey, array $extraMergeFields = []): array
    {
        if (! $this->allowed($lead, $channel)) {
            return ['success' => false, 'error' => "Lead is not opted in to {$channel}."];
        }

        $template = MessageTemplate::forTenant($lead->tenant_id)
            ->where('channel', $channel)
            ->where('key', $templateKey)
            ->where('status', 'active')
            ->first();

        if (! $template) {
            return ['success' => false, 'error' => "No active {$channel} template for key: {$templateKey}"];
        }

        $rendered = $this->render($template->body, $lead, $extraMergeFields);
        $subject = $template->subject ? $this->render($template->subject, $lead, $extraMergeFields) : null;

        return $this->send($lead, $channel, $rendered, $subject, $templateKey);
    }

    /**
     * @return array{success: bool, message?: ConversationMessage, error?: string}
     */
    public function send(Lead $lead, string $channel, string $body, ?string $subject = null, ?string $templateKey = null, ?string $sentBy = null, array $options = []): array
    {
        if (! $this->allowed($lead, $channel)) {
            return ['success' => false, 'error' => "Lead is not opted in to {$channel}."];
        }

        // Hard block on an explicit consent opt-out for this subject/channel,
        // independent of the per-lead flag (audit-trail source of truth).
        $subjectAddr = $this->subjectFor($lead, $channel);
        if ($subjectAddr && ConsentRecord::hasOptOut($lead->tenant_id, $subjectAddr, $channel)) {
            return ['success' => false, 'error' => "Subject has opted out of {$channel}."];
        }

        $conversation = Conversation::forTenant($lead->tenant_id)
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'channel' => $channel,
                'status' => 'open',
            ]);
        }

        $message = ConversationMessage::create([
            'tenant_id' => $lead->tenant_id,
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'body' => $body,
            'subject' => $subject,
            'template_key' => $templateKey,
            'status' => $channel === self::MANUAL_DM ? 'awaiting_operator' : 'queued',
            'sent_by' => $sentBy,
            'message_id_header' => $options['message_id'] ?? null,
            'in_reply_to' => $options['in_reply_to'] ?? null,
            'references_header' => ! empty($options['references']) ? implode(' ', (array) $options['references']) : null,
        ]);

        if ($channel === self::MANUAL_DM) {
            // Nothing leaves the system: a human copies the draft into the
            // social app and marks it sent from the cockpit queue.
            $conversation->update(['last_message_at' => now()]);
            $this->eventStore->append(
                $lead->tenant_id, 'conversation', $conversation->id, 'conversation.message.drafted',
                ['lead_id' => $lead->id, 'channel' => $channel, 'message_id' => $message->id],
            );

            return ['success' => true, 'message' => $message, 'provider_message_id' => null];
        }

        $result = match ($channel) {
            'email' => $this->emailChannel->send($lead, $body, ['subject' => $subject ?? 'A message from your team'] + $options),
            'whatsapp' => $this->whatsAppChannel->send($lead, $body),
            'sms' => $this->smsChannel->send($lead, $body),
            default => ['success' => false, 'error' => "Unsupported channel: {$channel}"],
        };

        $message->update([
            'status' => $result['success'] ? 'sent' : 'failed',
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'sent_at' => $result['success'] ? now() : null,
            'error' => $result['error'] ?? null,
        ]);

        $conversation->update(['last_message_at' => now()]);

        if (! $result['success']) {
            Log::warning('MessageDispatchService: send failed', ['lead_id' => $lead->id, 'channel' => $channel, 'error' => $result['error'] ?? null]);
        }

        $this->eventStore->append(
            $lead->tenant_id, 'conversation', $conversation->id,
            $result['success'] ? 'conversation.message.sent' : 'conversation.message.failed',
            ['lead_id' => $lead->id, 'channel' => $channel, 'message_id' => $message->id],
        );

        return array_merge($result, ['message' => $message]);
    }

    /** The subject identifier (phone/email) for a channel, for consent lookups. */
    private function subjectFor(Lead $lead, string $channel): ?string
    {
        return match ($channel) {
            'whatsapp' => $lead->whatsapp_number,
            'sms' => $lead->phone,
            'email' => $lead->email,
            default => null,
        };
    }

    private function allowed(Lead $lead, string $channel): bool
    {
        if ($channel === self::MANUAL_DM) {
            return empty($lead->consent['opted_out_at']);
        }

        return $lead->isOptedIn($channel);
    }

    private function render(string $template, Lead $lead, array $extra): string
    {
        return self::renderFields($template, array_merge([
            'lead.first_name' => trim(explode(' ', (string) ($lead->name ?? ''))[0]),
            'lead.name' => $lead->name ?? '',
            'lead.email' => $lead->email ?? '',
        ], $extra));
    }

    /**
     * Substitute {{ dotted.keys }} from $fields; unknown keys are left as-is
     * so a typo in a template is visible rather than silently blank.
     *
     * @param  array<string, string|int|float|null>  $fields
     */
    public static function renderFields(string $template, array $fields): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($fields) {
            return array_key_exists($m[1], $fields) ? (string) $fields[$m[1]] : $m[0];
        }, $template);
    }
}
