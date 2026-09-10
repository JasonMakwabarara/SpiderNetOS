<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\Tenant;

/**
 * Per-tenant partner-outreach configuration, stored under
 * tenants.settings['outreach'] (no migration: the column is already jsonb).
 *
 * Every consumer reads through for(), which layers the stored values over
 * defaults() so a tenant seeded before a key existed still gets a sane value.
 * Lists (sequence steps, warm-up ladder, exclusions) are replaced whole rather
 * than merged element-wise, so an operator can shorten them.
 */
class OutreachSettings
{
    public const KEY = 'outreach';

    public const REPLY_MODES = ['approve', 'auto'];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            // Facts the recruiter bot may quote. Never memorised by the model:
            // the prompt builder injects these verbatim.
            'program' => [
                'brand' => 'Hannah AI',
                'commission_pct' => 30,
                'months' => 12,
                'cookie_days' => 30,
                'min_payout_usd' => 50,
                'hold_days' => 30,
                'terms_url' => 'https://hannah-ai.world/affiliate-terms',
                'join_url' => null,
                'portal_name' => 'Affonso',
                'operator_legal_name' => 'Apex Synchronia LLC',
                'postal_address' => null,
                'exclusions' => ['Enterprise plans', 'self-referrals', 'brand bidding', 'coupon sites'],
            ],
            // Outbound cadence and blast-radius controls.
            'sending' => [
                'per_run_cap' => 5,
                'min_gap_seconds' => 120,
                'quiet_hours' => ['start' => '20:00', 'end' => '08:00'],
                'timezone' => 'UTC',
                'warmup' => [
                    ['from_day' => 1, 'cap' => 5],
                    ['from_day' => 4, 'cap' => 15],
                    ['from_day' => 8, 'cap' => 30],
                ],
                'hourly_burst_cap' => 40,
                'started_at' => null,
            ],
            'sequence' => [
                ['step' => 1, 'template' => 'partner.invite', 'wait_days' => 0],
                ['step' => 2, 'template' => 'partner.nudge', 'wait_days' => 4],
                ['step' => 3, 'template' => 'partner.last_call', 'wait_days' => 6],
            ],
            // approve = every bot reply becomes an Approval; auto = send immediately.
            'replies' => [
                'mode' => 'approve',
                'per_thread_daily_cap' => 3,
                'tenant_daily_cap' => 100,
            ],
            'mailbox' => [
                'from_name' => null,
                'from_address' => null,
                'imap_folder_unmatched' => 'Outreach/Unmatched',
                'imap_last_uid' => null,
            ],
        ];
    }

    /**
     * Effective settings for a tenant: stored values layered over defaults.
     *
     * @return array<string, mixed>
     */
    public function for(Tenant $tenant): array
    {
        $settings = (array) ($tenant->settings ?? []);
        $stored = (array) ($settings[self::KEY] ?? []);

        return self::merge(self::defaults(), $stored);
    }

    /**
     * Write defaults for every key the tenant has not set yet. Existing values
     * are never overwritten. Returns true when the row changed.
     */
    public function seedDefaults(Tenant $tenant): bool
    {
        $settings = (array) ($tenant->settings ?? []);
        $current = (array) ($settings[self::KEY] ?? []);
        $merged = self::merge(self::defaults(), $current);

        if ($merged === $current) {
            return false;
        }

        $settings[self::KEY] = $merged;
        $tenant->settings = $settings;
        $tenant->save();

        return true;
    }

    /**
     * Apply a partial update (deep-merged) and persist.
     *
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed> the effective settings after the update
     */
    public function update(Tenant $tenant, array $patch): array
    {
        $settings = (array) ($tenant->settings ?? []);
        $settings[self::KEY] = self::merge($this->for($tenant), $patch);
        $tenant->settings = $settings;
        $tenant->save();

        return $settings[self::KEY];
    }

    /**
     * Deep merge for associative arrays; lists and scalars in $over replace
     * the base value.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $baseValue = $base[$key] ?? null;

            if (is_array($value) && is_array($baseValue) && ! array_is_list($value) && ! array_is_list($baseValue)) {
                $base[$key] = self::merge($baseValue, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
