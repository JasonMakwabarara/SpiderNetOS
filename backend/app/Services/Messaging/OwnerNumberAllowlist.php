<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use App\Models\Tenant;
use App\Models\User;

/**
 * Which phone numbers belong to the tenant's owner/team rather than to a
 * prospect. Inbound WhatsApp from one of these must never become a Lead —
 * it is the owner talking to their own OS (routed as owner.message.received).
 *
 * Sources, merged:
 *   - tenants.settings.whatsapp.owner_numbers   (explicit allowlist, E.164)
 *   - users.preferences.{phone|whatsapp_number|whatsapp} of the tenant's users
 *     (the users table has no phone column; preferences is the existing home)
 *
 * Numbers are compared on digits only, so "+263 77 123 4567",
 * "whatsapp:+263771234567" and "263771234567" all match.
 */
class OwnerNumberAllowlist
{
    public const SETTINGS_KEY = 'whatsapp';

    public const SETTINGS_FIELD = 'owner_numbers';

    /** @var list<string> */
    private const PREFERENCE_KEYS = ['phone', 'whatsapp_number', 'whatsapp'];

    public function isOwnerNumber(string $tenantId, string $number): bool
    {
        $needle = self::normalize($number);
        if ($needle === '') {
            return false;
        }

        return in_array($needle, $this->numbersFor($tenantId), true);
    }

    /**
     * All normalised owner/team numbers for a tenant.
     *
     * @return list<string>
     */
    public function numbersFor(string $tenantId): array
    {
        $numbers = [];

        $tenant = Tenant::find($tenantId);
        $configured = (array) (($tenant?->settings[self::SETTINGS_KEY] ?? [])[self::SETTINGS_FIELD] ?? []);
        foreach ($configured as $raw) {
            $numbers[] = self::normalize((string) $raw);
        }

        User::where('tenant_id', $tenantId)
            ->whereNotNull('preferences')
            ->get(['preferences'])
            ->each(function (User $user) use (&$numbers): void {
                $preferences = (array) ($user->preferences ?? []);
                foreach (self::PREFERENCE_KEYS as $key) {
                    if (! empty($preferences[$key]) && is_string($preferences[$key])) {
                        $numbers[] = self::normalize($preferences[$key]);
                    }
                }
            });

        return array_values(array_unique(array_filter($numbers, static fn (string $n): bool => $n !== '')));
    }

    /** Digits only; strips whatsapp:/tel: prefixes, spaces, dashes, brackets and the leading +. */
    public static function normalize(string $number): string
    {
        $number = trim($number);
        foreach (['whatsapp:', 'tel:'] as $prefix) {
            if (str_starts_with(strtolower($number), $prefix)) {
                $number = substr($number, strlen($prefix));
            }
        }

        return (string) preg_replace('/\D+/', '', $number);
    }
}
