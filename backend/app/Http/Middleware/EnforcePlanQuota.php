<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Agent;
use App\Models\Flow;
use App\Models\User;
use App\Services\Billing\PlanEntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a plan's countable entitlements (agents|flows|seats) on CREATE
 * routes. Apply as `plan.quota:{resource}` ONLY to create endpoints — it
 * blocks when the tenant is already at the limit, so it must not sit on read
 * routes. Complements EnforcePlanLimits (which gates USD budget, not counts).
 *
 * Over limit → 402 with an upgrade hint. Unlimited (-1) passes through.
 */
class EnforcePlanQuota
{
    public function __construct(private readonly PlanEntitlementService $entitlements) {}

    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $tenantId = $request->attributes->get('tenant_id') ?? $request->user()?->tenant?->id;
        if (! $tenantId) {
            return $next($request);
        }

        $limit = $this->entitlements->limit((string) $tenantId, $resource);
        $current = $this->currentCount((string) $tenantId, $resource);

        if (PlanEntitlementService::exceedsLimit($limit, $current)) {
            return response()->json([
                'message' => "Your plan includes {$limit} {$resource}. Upgrade to add more.",
                'resource' => $resource,
                'limit' => $limit,
                'current' => $current,
                'upgrade_hint' => true,
            ], 402);
        }

        return $next($request);
    }

    private function currentCount(string $tenantId, string $resource): int
    {
        return match ($resource) {
            'agents' => Agent::where('tenant_id', $tenantId)->count(),
            'flows' => Flow::where('tenant_id', $tenantId)->count(),
            'seats' => User::where('tenant_id', $tenantId)->count(),
            default => 0,
        };
    }
}
