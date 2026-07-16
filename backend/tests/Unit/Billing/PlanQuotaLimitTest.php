<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Services\Billing\PlanEntitlementService;
use PHPUnit\Framework\TestCase;

/**
 * Pure limit-comparison logic used by EnforcePlanQuota. No DB.
 */
class PlanQuotaLimitTest extends TestCase
{
    public function test_under_limit_is_allowed(): void
    {
        // 2 of 3 used → creating the 3rd is allowed.
        $this->assertFalse(PlanEntitlementService::exceedsLimit(3, 2));
    }

    public function test_at_limit_is_blocked(): void
    {
        // 3 of 3 used → creating the 4th is blocked.
        $this->assertTrue(PlanEntitlementService::exceedsLimit(3, 3));
    }

    public function test_over_limit_is_blocked(): void
    {
        $this->assertTrue(PlanEntitlementService::exceedsLimit(3, 5));
    }

    public function test_unlimited_is_never_blocked(): void
    {
        $this->assertFalse(PlanEntitlementService::exceedsLimit(-1, 0));
        $this->assertFalse(PlanEntitlementService::exceedsLimit(-1, 10_000));
    }

    public function test_zero_limit_blocks_first(): void
    {
        $this->assertTrue(PlanEntitlementService::exceedsLimit(0, 0));
    }
}
