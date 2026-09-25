<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\NewsletterIssue;
use Illuminate\Support\Carbon;

/**
 * The 12-day cadence of the customer newsletter (plan D8 #16, Jason
 * 2026-09-16: "a newsletter that goes out to customers every 12 days").
 *
 * Twelve days, not "monthly" and not "every other Tuesday": a 12-day slot
 * walks through the week, so the list is never permanently stuck in the same
 * Monday-morning pile-up, and the business is not competing with itself for
 * the same slot every month.
 *
 * Quiet-day shifting moves a slot that lands on a weekend to the next working
 * day — but forward only, and without moving the *anchor*. If the shift moved
 * the anchor too, a single weekend would drag the whole schedule later for
 * good and the cadence would drift into a monthly one within a year.
 */
class CustomerNewsletterCadence
{
    public const INTERVAL_DAYS = 12;

    /** Slots landing on these ISO weekdays shift forward to the next working day. */
    public const QUIET_DAYS = [Carbon::SATURDAY, Carbon::SUNDAY];

    /**
     * The next slot on or after $from, ignoring quiet days.
     *
     * With no previous issue the first slot is today: a business that has just
     * switched the newsletter on should not wait twelve days to see a draft.
     */
    public function anchor(string $tenantId, Carbon $from): Carbon
    {
        $last = NewsletterIssue::forTenant($tenantId)
            ->ofKind(NewsletterIssue::KIND_CUSTOMER)
            ->orderByDesc('period')
            ->value('period');

        if ($last === null) {
            return $from->copy()->startOfDay();
        }

        $anchor = Carbon::parse((string) $last, $from->getTimezone())->startOfDay()->addDays(self::INTERVAL_DAYS);

        // A long pause (the flag was off, the tenant was asleep) must not fire a
        // backlog of issues: catch up to the present on the same 12-day grid.
        while ($anchor->lt($from->copy()->startOfDay())) {
            $anchor->addDays(self::INTERVAL_DAYS);
        }

        return $anchor;
    }

    /** The anchor, shifted off a quiet day. The anchor itself is unchanged. */
    public function nextSlot(string $tenantId, Carbon $from): Carbon
    {
        return self::shiftOffQuietDay($this->anchor($tenantId, $from));
    }

    /** Is $date the slot? Compared by date, so the daily tick can ask once a day. */
    public function isDue(string $tenantId, Carbon $date): bool
    {
        return $this->nextSlot($tenantId, $date)->isSameDay($date);
    }

    /** The period key an issue is filed under: its send date. */
    public function periodFor(Carbon $slot): string
    {
        return $slot->toDateString();
    }

    /**
     * The window an issue covers: since the previous issue, or the last 12 days
     * when this is the first one.
     */
    public function coversSince(string $tenantId, Carbon $slot): Carbon
    {
        $last = NewsletterIssue::forTenant($tenantId)
            ->ofKind(NewsletterIssue::KIND_CUSTOMER)
            ->where('period', '<', $this->periodFor($slot))
            ->orderByDesc('period')
            ->value('period');

        return $last === null
            ? $slot->copy()->subDays(self::INTERVAL_DAYS)->startOfDay()
            : Carbon::parse((string) $last, $slot->getTimezone())->startOfDay();
    }

    /** @return list<string> the next $count send dates, for the cockpit to show */
    public function upcoming(string $tenantId, Carbon $from, int $count = 4): array
    {
        $anchor = $this->anchor($tenantId, $from);
        $dates = [];

        for ($i = 0; $i < max(1, $count); $i++) {
            $dates[] = self::shiftOffQuietDay($anchor->copy()->addDays(self::INTERVAL_DAYS * $i))->toDateString();
        }

        return $dates;
    }

    public static function shiftOffQuietDay(Carbon $date): Carbon
    {
        $shifted = $date->copy();
        while (in_array($shifted->dayOfWeek, self::QUIET_DAYS, true)) {
            $shifted->addDay();
        }

        return $shifted;
    }
}
