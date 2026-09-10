<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\PartnerProspect;
use App\Models\Tenant;
use App\Services\EventStore;
use App\Services\Messaging\TenantMailerFactory;
use App\Services\Outreach\ProspectStateMachine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns one inbound email into state: dedupe on Message-ID, classify, match
 * the prospect, store the turn on the email conversation, then apply the
 * lifecycle effect (bounce, opt-out, reply). Replies raise
 * conversation.message.received tagged bridge=laravel_outreach so the Laravel
 * recruiter loop, not the Python CRM agent, picks them up.
 */
class InboundIngestor
{
    public const DUPLICATE = 'duplicate';

    public const OWN = 'own';

    public const UNMATCHED = 'unmatched';

    public function __construct(
        private readonly InboundEmailClassifier $classifier,
        private readonly ThreadMatcher $matcher,
        private readonly ProspectStateMachine $lifecycle,
        private readonly TenantMailerFactory $mailers,
        private readonly EventStore $events,
    ) {}

    /**
     * @return array{action: string, prospect_id: ?string, message_id: ?string}
     */
    public function ingest(Tenant $tenant, InboundMessage $message, bool $dryRun = false): array
    {
        $tenantId = (string) $tenant->id;
        $key = $message->dedupeKey();

        if (ConversationMessage::forTenant($tenantId)->where('message_id_header', $key)->exists()) {
            return ['action' => self::DUPLICATE, 'prospect_id' => null, 'message_id' => null];
        }

        $mailbox = $this->mailers->senderFor($this->mailers->credentialsFor($tenantId));
        $classified = $this->classifier->classify($message);

        if ($classified['kind'] === InboundEmailClassifier::BOUNCE) {
            return $this->bounce($tenant, $message, (array) $classified['dsn'], $classified['text'], $dryRun);
        }

        $ownDomain = strtolower(Str::after($mailbox['address'], '@'));
        if ($ownDomain !== '' && str_ends_with(strtolower($message->from), '@'.$ownDomain)) {
            return ['action' => self::OWN, 'prospect_id' => null, 'message_id' => null];
        }

        $prospect = $this->matcher->match($tenantId, $message, $mailbox['address']);
        if ($prospect === null) {
            Log::info('outreach inbound unmatched', ['tenant_id' => $tenantId, 'from' => $message->from, 'subject' => $message->subject]);

            return ['action' => self::UNMATCHED, 'prospect_id' => null, 'message_id' => null];
        }

        if ($dryRun) {
            return ['action' => $classified['kind'], 'prospect_id' => (string) $prospect->id, 'message_id' => null];
        }

        $stored = $this->store($tenant, $prospect, $message, $classified['kind'], $classified['text']);

        switch ($classified['kind']) {
            case InboundEmailClassifier::OPT_OUT:
                $this->lifecycle->optOut($prospect, 'inbound_stop', ['message_id' => $stored->id]);
                break;
            case InboundEmailClassifier::DECLINE:
            case InboundEmailClassifier::REPLY:
                $this->lifecycle->markReplied($prospect);
                $this->events->append($tenantId, 'conversation', (string) $stored->conversation_id, 'conversation.message.received', [
                    'lead_id' => $prospect->lead_id, 'channel' => 'email', 'message_id' => $stored->id,
                    'prospect_id' => $prospect->id, 'classification' => $classified['kind'], 'bridge' => 'laravel_outreach',
                ]);
                break;
            default:
                // auto_reply: stored for the record, no state change.
                break;
        }

        return ['action' => $classified['kind'], 'prospect_id' => (string) $prospect->id, 'message_id' => (string) $stored->id];
    }

    /** @param array<string, mixed> $dsn */
    private function bounce(Tenant $tenant, InboundMessage $message, array $dsn, string $text, bool $dryRun): array
    {
        $tenantId = (string) $tenant->id;

        $prospect = $this->matcher->byEmail($tenantId, $dsn['recipient'] ?? null)
            ?? $this->matcher->byMessageIds($tenantId, array_filter([$dsn['original_message_id'] ?? null]));

        if ($prospect === null) {
            Log::info('outreach bounce unmatched', ['tenant_id' => $tenantId, 'recipient' => $dsn['recipient'] ?? null]);

            return ['action' => self::UNMATCHED, 'prospect_id' => null, 'message_id' => null];
        }

        if ($dryRun) {
            return ['action' => InboundEmailClassifier::BOUNCE, 'prospect_id' => (string) $prospect->id, 'message_id' => null];
        }

        $stored = $this->store($tenant, $prospect, $message, InboundEmailClassifier::BOUNCE, $text, [
            'dsn_status' => $dsn['status'] ?? null, 'dsn_action' => $dsn['action'] ?? null, 'dsn_recipient' => $dsn['recipient'] ?? null,
        ]);

        if (! empty($dsn['hard'])) {
            $reason = (string) (($dsn['diagnostic'] ?? null) ?: ($dsn['status'] ?? 'hard bounce'));
            $this->lifecycle->markBounced($prospect, $reason);
        } else {
            // Soft bounce (mailbox full, greylisting): try again tomorrow.
            PartnerProspect::whereKey($prospect->id)->whereNotNull('next_send_at')->update(['next_send_at' => now()->addDay()]);
        }

        return ['action' => InboundEmailClassifier::BOUNCE, 'prospect_id' => (string) $prospect->id, 'message_id' => (string) $stored->id];
    }

    /** @param array<string, mixed> $extraHeaders */
    private function store(Tenant $tenant, PartnerProspect $prospect, InboundMessage $message, string $classification, string $text, array $extraHeaders = []): ConversationMessage
    {
        $conversation = Conversation::firstOrCreate(
            ['tenant_id' => $tenant->id, 'lead_id' => $prospect->lead_id, 'channel' => 'email'],
            ['status' => 'open'],
        );

        $stored = ConversationMessage::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'body' => $text !== '' ? $text : $message->textBody,
            'subject' => mb_substr($message->subject, 0, 255),
            'status' => 'received',
            'classification' => $classification,
            'message_id_header' => mb_substr($message->dedupeKey(), 0, 255),
            'in_reply_to' => $message->inReplyTo[0] ?? null,
            'references_header' => $message->references !== [] ? implode(' ', $message->references) : null,
            'headers' => array_filter([
                'from' => $message->from,
                'from_name' => $message->fromName,
                'to' => implode(', ', $message->recipients),
                'date' => $message->date?->format('c'),
                'auto_submitted' => $message->header('auto-submitted'),
                'uid' => $message->uid,
            ] + $extraHeaders, fn ($v) => $v !== null && $v !== ''),
        ]);

        $conversation->update([
            'status' => in_array($classification, [InboundEmailClassifier::REPLY, InboundEmailClassifier::DECLINE], true) ? 'pending' : $conversation->status,
            'last_message_at' => now(),
        ]);
        PartnerProspect::whereKey($prospect->id)->update(['last_inbound_at' => now()]);

        return $stored;
    }
}
