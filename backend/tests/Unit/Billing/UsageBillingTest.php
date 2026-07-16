<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\UsageBilling;
use PHPUnit\Framework\TestCase;

/**
 * Pure pricing-math tests — no DB, no Laravel bootstrap.
 */
class UsageBillingTest extends TestCase
{
    public function test_no_overage_when_under_allowance(): void
    {
        // $30 used against a $50 allowance → nothing billable.
        $this->assertSame(0, UsageBilling::overageCents(3000, 5000, 15));
    }

    public function test_no_overage_at_exactly_the_allowance(): void
    {
        $this->assertSame(0, UsageBilling::overageCents(5000, 5000, 15));
    }

    public function test_overage_applies_margin(): void
    {
        // $60 used, $50 included → $10 over, +15% margin = $11.50 = 1150c.
        $this->assertSame(1150, UsageBilling::overageCents(6000, 5000, 15));
    }

    public function test_overage_rounds_to_nearest_cent(): void
    {
        // $1.00 over at 15% = 115c exactly; $1.01 over = 116.15 → 116c.
        $this->assertSame(115, UsageBilling::overageCents(5100, 5000, 15));
        $this->assertSame(116, UsageBilling::overageCents(5101, 5000, 15));
    }

    public function test_zero_margin_passes_through_at_cost(): void
    {
        $this->assertSame(2000, UsageBilling::overageCents(7000, 5000, 0));
    }

    public function test_projection_scales_run_rate_to_month_end(): void
    {
        // $50 spent by day 10 of a 30-day month → ~$150 projected.
        $this->assertSame(15000, UsageBilling::projectToMonthEnd(5000, 10, 30));
    }

    public function test_projection_guards_day_zero(): void
    {
        // Defensive: day 0 must not divide-by-zero; treated as day 1.
        $this->assertSame(31000, UsageBilling::projectToMonthEnd(1000, 0, 31));
    }
}
