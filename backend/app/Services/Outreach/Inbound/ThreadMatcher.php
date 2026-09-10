<?php

declare(strict_types=1);

namespace App\Services\Outreach\Inbound;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Lead;
use App\Models\PartnerProspect;
use Illuminate\Support\Str;

/**
 * Ties an inbound email back to a prospect: the plus-address token we put in
 * Reply-To first, then the threading headers against our own Message-IDs,
 * then the sender address. All lookups are tenant-scoped.
 */
class ThreadMatcher
{
    public function match(string $tenantId, InboundMessage $message, string $mailboxAddress): ?PartnerProspect
    {
        return $this->byPlusAddress($tenantId, $message, $mailboxAddress)
            ?? $this->byMessageIds($tenantId, array_merge($message->inReplyTo, $message->references))
            ?? $this->byEmail($tenantId, $message->from);
    }

    public function byPlusAddress(string $tenantId, InboundMessage $message, string $mailboxAddress): ?PartnerProspect
    {
        $mailboxAddress = strtolower(trim($mailboxAddress));
        if ($mailboxAddress === '' || ! str_contains($mailboxAddress, '@')) {
            return null;
        }
        $local = preg_quote(Str::before($mailboxAddress, '@'), '/');
        $domain = preg_quote(Str::after($mailboxAddress, '@'), '/');

        foreach ($message->recipients as $recipient) {
            if (preg_match('/^'.$local.'\+([A-Za-z0-9]{22})@'.$domain.'$/i', trim($recipient), $m) === 1) {
                $prospect = PartnerProspect::forTenant($tenantId)->where('invite_token', strtolower($m[1]))->first();
                if ($prospect !== null) {
                    return $prospect;
                }
            }
        }

        return null;
    }

    /** @param list<string> $ids */
    public function byMessageIds(string $tenantId, array $ids): ?PartnerProspect
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return null;
        }

        $conversationIds = ConversationMessage::forTenant($tenantId)->whereIn('message_id_header', $ids)->pluck('conversation_id');
        if ($conversationIds->isEmpty()) {
            return null;
        }

        $leadIds = Conversation::forTenant($tenantId)->whereIn('id', $conversationIds)->pluck('lead_id');

        return PartnerProspect::forTenant($tenantId)->whereIn('lead_id', $leadIds)->first();
    }

    public function byEmail(string $tenantId, ?string $email): ?PartnerProspect
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        $leadId = Lead::forTenant($tenantId)->where('email', $email)->value('id');

        return $leadId ? PartnerProspect::forTenant($tenantId)->where('lead_id', $leadId)->first() : null;
    }
}
