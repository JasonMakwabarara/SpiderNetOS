<?php

declare(strict_types=1);

namespace App\Services\Outreach\Channels;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What keeps a LinkedIn account alive (plan D7 §3).
 *
 * These are not throughput limits, they are the difference between an account
 * that works next month and one that is restricted. LinkedIn does not publish
 * its thresholds; the numbers below are the conservative consensus from
 * accounts that survive, and being under them is worth more than any week's
 * extra volume.
 *
 *   - ≤100 connection requests a week
 *   - ≤200 actions a day of any kind
 *   - a warm-up ramp for a new sender: a brand-new account doing 100 connects
 *     on day one is the clearest automation signal there is
 *   - quiet hours in the tenant's own timezone, because a human does not send
 *     forty messages at 03:00
 *
 * The governor answers "may this go now", and when the answer is no it says
 * which rule stopped it and when it would next be allowed. A refusal without
 * a reason and a time is an outage; with them it is a schedule.
 */
class LinkedInSafetyGovernor
{
    public const MAX_CONNECTS_PER_WEEK = 100;

    public const MAX_ACTIONS_PER_DAY = 200;

    /** Days of ramp before a sender is at full allowance. */
    public const WARMUP_DAYS = 14;

    /** A new sender starts here and climbs to MAX_CONNECTS_PER_WEEK over WARMUP_DAYS. */
    public const WARMUP_FIRST_DAY_CONNECTS = 5;

    public const QUIET_FROM_HOUR = 20;

    public const QUIET_UNTIL_HOUR = 7;

    public const ACTION_CONNECT = 'connect';

    public const ACTION_MESSAGE = 'message';

    /**
     * @param  string  $action  connect | message
     * @return array{allowed: bool, reason: string, retry_after: string|null, remaining: array<string, int>}
     */
    public function check(Tenant $tenant, string $action = self::ACTION_CONNECT, ?Carbon $now = null): array
    {
        $zone = $this->timezoneOf($tenant);
        $now = ($now ?? Carbon::now())->copy()->setTimezone($zone);
        $tenantId = (string) $tenant->id;

        $connectsThisWeek = $this->countSince($tenantId, $now->copy()->subDays(7));
        $actionsToday = $this->countSince($tenantId, $now->copy()->startOfDay());
        $weeklyCap = $this->weeklyCap($tenant, $now);

        $remaining = [
            'connects_this_week' => max(0, $weeklyCap - $connectsThisWeek),
            'actions_today' => max(0, self::MAX_ACTIONS_PER_DAY - $actionsToday),
            'weekly_cap' => $weeklyCap,
        ];

        if ($this->inQuietHours($now)) {
            return [
                'allowed' => false,
                'reason' => sprintf('quiet hours (%02d:00–%02d:00 %s)', self::QUIET_FROM_HOUR, self::QUIET_UNTIL_HOUR, $zone),
                'retry_after' => $this->nextOpenHour($now)->toIso8601String(),
                'remaining' => $remaining,
            ];
        }

        if ($actionsToday >= self::MAX_ACTIONS_PER_DAY) {
            return [
                'allowed' => false,
                'reason' => 'the daily action limit of '.self::MAX_ACTIONS_PER_DAY.' has been reached',
                'retry_after' => $now->copy()->addDay()->startOfDay()->setHour(self::QUIET_UNTIL_HOUR)->toIso8601String(),
                'remaining' => $remaining,
            ];
        }

        if ($action === self::ACTION_CONNECT && $connectsThisWeek >= $weeklyCap) {
            return [
                'allowed' => false,
                'reason' => $weeklyCap < self::MAX_CONNECTS_PER_WEEK
                    ? "this sender is still warming up; its cap this week is {$weeklyCap} connections"
                    : 'the weekly connection limit of '.self::MAX_CONNECTS_PER_WEEK.' has been reached',
                'retry_after' => $now->copy()->addDay()->startOfDay()->setHour(self::QUIET_UNTIL_HOUR)->toIso8601String(),
                'remaining' => $remaining,
            ];
        }

        return ['allowed' => true, 'reason' => '', 'retry_after' => null, 'remaining' => $remaining];
    }

    /**
     * The weekly connection allowance, ramped for a new sender.
     *
     * Ramp is linear from WARMUP_FIRST_DAY_CONNECTS to the full cap across
     * WARMUP_DAYS. A sender with no recorded start is treated as brand new —
     * fail slow rather than fast, because the cost of being wrong is somebody
     * losing their LinkedIn account.
     */
    public function weeklyCap(Tenant $tenant, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $startedAt = $this->senderStartedAt($tenant);

        if ($startedAt === null) {
            return self::WARMUP_FIRST_DAY_CONNECTS;
        }

        $days = max(0, (int) $startedAt->diffInDays($now));
        if ($days >= self::WARMUP_DAYS) {
            return self::MAX_CONNECTS_PER_WEEK;
        }

        $span = self::MAX_CONNECTS_PER_WEEK - self::WARMUP_FIRST_DAY_CONNECTS;

        return (int) round(self::WARMUP_FIRST_DAY_CONNECTS + ($span * $days / self::WARMUP_DAYS));
    }

    public function inQuietHours(Carbon $localNow): bool
    {
        $hour = (int) $localNow->format('G');

        return $hour >= self::QUIET_FROM_HOUR || $hour < self::QUIET_UNTIL_HOUR;
    }

    private function nextOpenHour(Carbon $localNow): Carbon
    {
        $hour = (int) $localNow->format('G');

        return $hour >= self::QUIET_FROM_HOUR
            ? $localNow->copy()->addDay()->startOfDay()->setHour(self::QUIET_UNTIL_HOUR)
            : $localNow->copy()->startOfDay()->setHour(self::QUIET_UNTIL_HOUR);
    }

    /** Sends recorded against the DM queue since a moment. */
    private function countSince(string $tenantId, Carbon $since): int
    {
        if (! Schema::hasTable('partner_prospects')) {
            return 0;
        }

        return DB::table('partner_prospects')
            ->where('tenant_id', $tenantId)
            ->where('dm_sent_at', '>=', $since)
            ->count();
    }

    private function senderStartedAt(Tenant $tenant): ?Carbon
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $startedAt = data_get($settings, LinkedInToSGate::SETTINGS_KEY.'.sender_started_at');

        if (! is_string($startedAt) || $startedAt === '') {
            return null;
        }

        try {
            return Carbon::parse($startedAt);
        } catch (\Throwable) {
            return null;
        }
    }

    private function timezoneOf(Tenant $tenant): string
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $zone = (string) (data_get($settings, 'outreach.sending.timezone')
            ?: data_get($settings, 'timezone') ?: 'UTC');

        try {
            new \DateTimeZone($zone);
        } catch (\Throwable) {
            return 'UTC';
        }

        return $zone;
    }
}
