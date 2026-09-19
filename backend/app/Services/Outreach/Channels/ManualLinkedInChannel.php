<?php

declare(strict_types=1);

namespace App\Services\Outreach\Channels;

use App\Models\PartnerProspect;
use Illuminate\Support\Facades\Log;

/**
 * Richard's default channel: he drafts, a person sends (plan D7 §3).
 *
 * This is not a stub waiting for the real one. Draft-only is a legitimate and
 * probably permanent operating mode: it is the only LinkedIn outreach that
 * does not touch LinkedIn's User Agreement at all, because a human opens
 * LinkedIn and sends the message themselves. Everything else — the safety
 * governor, the connection caps, the acknowledgement — exists for the day
 * somebody chooses to go further, and none of it is needed here.
 *
 * Limits are LinkedIn's own: 300 characters on a connection note, and a
 * practical ceiling on a first message. They are enforced at draft time, not
 * at send time, because a draft a human cannot actually send is a waste of
 * their attention.
 */
class ManualLinkedInChannel implements OutreachChannel
{
    /** LinkedIn's hard limit on a connection request note. */
    public const MAX_CONNECT_CHARS = 300;

    /** A first DM longer than this does not get read. */
    public const MAX_MESSAGE_CHARS = 1200;

    public function key(): string
    {
        return 'manual_linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn (you send)';
    }

    public function canSend(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    public function draft(string $tenantId, string $prospectId, array $message): array
    {
        $kind = ($message['kind'] ?? 'message') === 'connect' ? 'connect' : 'message';
        $limit = $kind === 'connect' ? self::MAX_CONNECT_CHARS : self::MAX_MESSAGE_CHARS;

        $body = trim((string) ($message['body'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('An empty draft is not a draft.');
        }

        $overLimit = mb_strlen($body) > $limit;

        $prospect = PartnerProspect::where('tenant_id', $tenantId)->where('id', $prospectId)->first();
        if ($prospect !== null) {
            $prospect->forceFill([
                'dm_draft_at' => now(),
                'notes' => $body,
            ])->save();
        }

        Log::info('outreach.linkedin.drafted', [
            'tenant_id' => $tenantId, 'prospect_id' => $prospectId, 'kind' => $kind, 'chars' => mb_strlen($body),
        ]);

        return [
            'channel' => $this->key(),
            'kind' => $kind,
            'body' => $body,
            'chars' => mb_strlen($body),
            'limit' => $limit,
            // Surfaced rather than truncated: silently cutting someone's
            // outreach mid-sentence is worse than telling them it is long.
            'over_limit' => $overLimit,
            'sendable_here' => false,
            'drafted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function send(string $tenantId, string $prospectId, array $draft): array
    {
        throw new ChannelCannotSendException(
            'This workspace drafts LinkedIn messages and you send them. Nothing here contacts LinkedIn.',
        );
    }
}
