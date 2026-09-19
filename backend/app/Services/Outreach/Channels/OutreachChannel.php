<?php

declare(strict_types=1);

namespace App\Services\Outreach\Channels;

/**
 * A place outreach can go (plan D7 §3).
 *
 * The contract exists so that "Richard drafts, a human sends" and "an
 * automation sends" are two implementations of one interface rather than two
 * code paths that drift apart — and so the safety rules sit above the channel
 * instead of inside whichever one happens to be selected.
 *
 * `ManualLinkedInChannel` is the default and the only one built: it drafts
 * into the DM queue and refuses to send. A HeyReach channel would sit beside
 * it behind `outreach.heyreach`, and behind a versioned acknowledgement,
 * because LinkedIn automation is against LinkedIn's User Agreement and that
 * is the account owner's risk to accept explicitly rather than ours to
 * assume quietly.
 */
interface OutreachChannel
{
    /** Stable identifier: `manual_linkedin`, `heyreach`, … */
    public function key(): string;

    /** Human-readable, for the settings screen and the approval card. */
    public function label(): string;

    /** Can this channel actually deliver, or does a human press send? */
    public function canSend(): bool;

    /**
     * Turn a prospect and a composed message into a draft the human can see.
     *
     * Every channel implements this. A channel that can send still drafts
     * first — the draft is the thing a person approves.
     *
     * @param  array<string, mixed>  $message  {kind: connect|message, body, subject?}
     * @return array<string, mixed> the stored draft
     */
    public function draft(string $tenantId, string $prospectId, array $message): array;

    /**
     * Deliver an approved draft.
     *
     * A channel that cannot send throws `ChannelCannotSendException` rather
     * than returning a falsy value. A silent no-op here would look exactly
     * like a successful send in every log and every dashboard.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function send(string $tenantId, string $prospectId, array $draft): array;
}
