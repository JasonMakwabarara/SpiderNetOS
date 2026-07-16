<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Pure pricing math for the platform-fee + usage-pass-through model. No DB,
 * no framework — shared by BillingController (projections) and the monthly
 * invoicer (GeneratePlatformInvoices).
 */
final class UsageBilling
{
    /**
     * Billable overage in cents: usage above the included allowance, marked up
     * by the plan margin. overage = max(0, metered - included) * (1 + margin%).
     */
    public static function overageCents(int $meteredCents, int $includedCents, int $marginPct): int
    {
        $over = max(0, $meteredCents - $includedCents);

        return (int) round($over * (1 + $marginPct / 100));
    }

    /**
     * Straight-line run-rate projection of month-to-date usage to month end.
     */
    public static function projectToMonthEnd(int $meteredCents, int $dayOfMonth, int $daysInMonth): int
    {
        $dayOfMonth = max(1, $dayOfMonth);

        return (int) round($meteredCents / $dayOfMonth * $daysInMonth);
    }
}
