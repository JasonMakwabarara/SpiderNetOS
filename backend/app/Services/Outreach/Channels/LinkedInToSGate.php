<?php

declare(strict_types=1);

namespace App\Services\Outreach\Channels;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The acknowledgement that has to exist before anything automates LinkedIn
 * (plan D7 §3).
 *
 * LinkedIn's User Agreement prohibits automated access, and every LinkedIn
 * automation tool — HeyReach included — operates against it. The risk of an
 * account restriction lands on the person whose LinkedIn account it is, not
 * on SpiderNetOS. So that person acknowledges it themselves, by name, with a
 * timestamp, against a **version** of the text.
 *
 * Versioned on purpose: if the wording of the risk changes, an
 * acknowledgement of the old wording is not an acknowledgement of the new
 * one. Bumping ACK_VERSION re-asks everybody, which is the point.
 *
 * `draft_only` needs no acknowledgement at all. Drafting a message a human
 * then types into LinkedIn themselves is not automated access — it is
 * writing. That is why draft_only is the default and why it stays usable
 * without anyone signing anything.
 */
class LinkedInToSGate
{
    /** Bump this when the acknowledgement text changes; everyone re-acknowledges. */
    public const ACK_VERSION = '2026-09-19.1';

    /** Drafts into the DM queue; a human sends. No acknowledgement needed. */
    public const MODE_DRAFT_ONLY = 'draft_only';

    /** A connected automation delivers, still one approval per send. */
    public const MODE_ASSISTED = 'assisted';

    public const MODES = [self::MODE_DRAFT_ONLY, self::MODE_ASSISTED];

    public const SETTINGS_KEY = 'outreach.linkedin';

    /**
     * The text being acknowledged. Kept in code beside the version so the two
     * cannot drift, and so what somebody agreed to is recoverable from git.
     */
    public const ACK_TEXT = <<<'TEXT'
        LinkedIn's User Agreement prohibits automated access to the service. Tools that send
        connection requests or messages on your behalf operate against it, and LinkedIn may
        restrict or permanently close an account it believes is automated.

        That risk is yours, on your account. SpiderNetOS drafts; switching this workspace to
        assisted sending means you accept that an automation will act on your LinkedIn account
        and that any restriction of it is your own. You can return to draft-only at any time.
        TEXT;

    /** @return array<string, mixed> */
    public function settings(Tenant $tenant): array
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $linkedin = (array) data_get($settings, self::SETTINGS_KEY, []);

        $mode = (string) ($linkedin['mode'] ?? self::MODE_DRAFT_ONLY);
        if (! in_array($mode, self::MODES, true)) {
            $mode = self::MODE_DRAFT_ONLY;
        }

        return [
            'mode' => $mode,
            'tos_acknowledged_at' => $linkedin['tos_acknowledged_at'] ?? null,
            'tos_acknowledged_by' => $linkedin['tos_acknowledged_by'] ?? null,
            'tos_acknowledged_version' => $linkedin['tos_acknowledged_version'] ?? null,
            'sender_account_id' => $linkedin['sender_account_id'] ?? null,
            'ack_required_version' => self::ACK_VERSION,
            'ack_current' => $this->acknowledged($tenant),
            'ack_text' => self::ACK_TEXT,
        ];
    }

    /** Has this tenant acknowledged the *current* version? */
    public function acknowledged(Tenant $tenant): bool
    {
        $settings = $this->rawLinkedIn($tenant);

        return ($settings['tos_acknowledged_version'] ?? null) === self::ACK_VERSION
            && ! empty($settings['tos_acknowledged_at']);
    }

    /**
     * May this tenant use a channel that actually sends?
     *
     * @return array{allowed: bool, reason: string}
     */
    public function allowsAutomation(Tenant $tenant): array
    {
        $settings = $this->rawLinkedIn($tenant);
        $mode = (string) ($settings['mode'] ?? self::MODE_DRAFT_ONLY);

        if ($mode !== self::MODE_ASSISTED) {
            return ['allowed' => false, 'reason' => 'this workspace is in draft-only mode'];
        }
        if (! $this->acknowledged($tenant)) {
            return ['allowed' => false, 'reason' => 'the current LinkedIn terms acknowledgement has not been given'];
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * Record an acknowledgement, or switch back to draft-only.
     *
     * Moving to `assisted` without acknowledging is refused rather than
     * silently downgraded: a settings screen that accepts a change and then
     * does something else is worse than one that says no.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException
     */
    public function update(Tenant $tenant, User $actor, string $mode, bool $acknowledge, ?string $senderAccountId = null): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown LinkedIn mode.');
        }

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $linkedin = $this->rawLinkedIn($tenant);

        if ($mode === self::MODE_ASSISTED) {
            $alreadyCurrent = $this->acknowledged($tenant);
            if (! $acknowledge && ! $alreadyCurrent) {
                throw new \InvalidArgumentException(
                    'Assisted sending needs the LinkedIn terms acknowledgement, given by name.',
                );
            }
            if ($acknowledge) {
                $linkedin['tos_acknowledged_at'] = Carbon::now()->toIso8601String();
                // The person, not the tenant: an acknowledgement with no name
                // on it is not an acknowledgement.
                $linkedin['tos_acknowledged_by'] = (string) $actor->email;
                $linkedin['tos_acknowledged_version'] = self::ACK_VERSION;
            }
        }

        $linkedin['mode'] = $mode;
        if ($senderAccountId !== null) {
            $linkedin['sender_account_id'] = $senderAccountId === '' ? null : mb_substr($senderAccountId, 0, 120);
        }

        data_set($settings, self::SETTINGS_KEY, $linkedin);
        $tenant->forceFill(['settings' => $settings])->save();

        return $this->settings($tenant->fresh());
    }

    /** @return array<string, mixed> */
    private function rawLinkedIn(Tenant $tenant): array
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];

        return (array) data_get($settings, self::SETTINGS_KEY, []);
    }
}
