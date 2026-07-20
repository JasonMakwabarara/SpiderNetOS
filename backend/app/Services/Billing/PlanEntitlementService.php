<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\PackEntitlement;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;

/**
 * Resolves a tenant's effective plan entitlements (agents/flows/seats/pack_slots
 * limits + included usage), enforced server-side by EnforcePlanQuota.
 *
 * Resolution order:
 *   1. the tenant's live tenant_subscriptions row (source of truth), else
 *   2. a mapping from the legacy `tenants.plan` string (so tenants not yet
 *      migrated onto a subscription still resolve sensibly), else
 *   3. the entry plan (launch).
 *
 * Memoised per request. NOTE (later): once subscription webhooks land, add a
 * cross-request cache keyed by tenant, invalidated on subscription.* events.
 */
class PlanEntitlementService
{
    /** @var array<string, Plan> */
    private array $memo = [];

    private const DEFAULT_PLAN_ID = 'launch';

    /** Legacy tenants.plan string → catalog plan id. */
    private const LEGACY_PLAN_MAP = [
        'free' => 'launch',
        'starter' => 'launch',
        'launch' => 'launch',
        'pro' => 'growth',
        'growth' => 'growth',
        'enterprise' => 'enterprise',
    ];

    /**
     * True when creating one more of $resource would exceed the plan limit.
     * Pure — a limit of -1 means unlimited.
     */
    public static function exceedsLimit(int $limit, int $currentCount): bool
    {
        if ($limit < 0) {
            return false;
        }

        return $currentCount >= $limit;
    }

    public function planFor(string $tenantId): Plan
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        return $this->memo[$tenantId] = $this->resolve($tenantId);
    }

    /** A single entitlement limit (agents|flows|seats|pack_slots). -1 = unlimited. */
    public function limit(string $tenantId, string $key): int
    {
        return $this->planFor($tenantId)->entitlement($key, 0);
    }

    public function includedUsageCents(string $tenantId): int
    {
        return $this->planFor($tenantId)->included_usage_cents;
    }

    /**
     * Does the tenant's plan cover installing $packId without a separate
     * purchase? pack_slots = -1 → all packs; otherwise the pack is included if
     * it's already entitled or the tenant is under their included-pack count.
     */
    public function packIncluded(string $tenantId, string $packId): bool
    {
        $slots = $this->limit($tenantId, 'pack_slots');
        if ($slots < 0) {
            return true;
        }
        if ($slots === 0) {
            return false;
        }

        $active = PackEntitlement::forTenant($tenantId)->active();
        if ((clone $active)->where('pack_id', $packId)->exists()) {
            return true;
        }

        return (clone $active)->distinct()->count('pack_id') < $slots;
    }

    private function resolve(string $tenantId): Plan
    {
        $sub = TenantSubscription::forTenant($tenantId)->live()->latest()->first();
        if ($sub && ($plan = Plan::find($sub->plan_id))) {
            return $plan;
        }

        $tenant = Tenant::find($tenantId);
        $mappedId = self::LEGACY_PLAN_MAP[strtolower((string) $tenant?->plan)] ?? self::DEFAULT_PLAN_ID;

        return Plan::find($mappedId)
            ?? Plan::find(self::DEFAULT_PLAN_ID)
            ?? $this->fallbackPlan();
    }

    /** Conservative default if the catalog hasn't been seeded yet. */
    private function fallbackPlan(): Plan
    {
        return new Plan([
            'id' => self::DEFAULT_PLAN_ID,
            'name' => 'Launch',
            'monthly_fee_cents' => 19900,
            'included_usage_cents' => 5000,
            'usage_margin_pct' => 15,
            'entitlements' => ['agents' => 3, 'flows' => 10, 'seats' => 3, 'pack_slots' => 1],
        ]);
    }
}
