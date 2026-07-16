<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Billing\UsageBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing & plan summary for the Cockpit (tenant-scoped).
 */
class BillingController extends Controller
{
    /**
     * GET /api/billing/plans — the public plan catalog.
     */
    public function plans(): JsonResponse
    {
        $plans = Plan::active()->orderBy('sort')->get()->map(fn (Plan $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'tagline' => $p->tagline,
            'monthly_fee_cents' => $p->monthly_fee_cents,
            'monthly_fee' => round($p->monthly_fee_cents / 100, 2),
            'included_usage_cents' => $p->included_usage_cents,
            'included_usage' => round($p->included_usage_cents / 100, 2),
            'currency' => $p->currency,
            'usage_margin_pct' => $p->usage_margin_pct,
            'is_custom' => $p->is_custom,
            'entitlements' => $p->entitlements,
        ]);

        return response()->json(['data' => $plans]);
    }

    /**
     * GET /api/billing/summary
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $tenantId = (string) $tenant->id;

        $limits = $tenant->limits ?? [
            'agents' => 5,
            'flows' => 10,
        ];

        $dailySpend = 0.0;
        $monthlySpend = 0.0;

        if (Schema::hasTable('usage_daily_aggregates')) {
            $dailySpend = (float) DB::table('usage_daily_aggregates')
                ->where('tenant_id', $tenantId)
                ->whereDate('date', now()->toDateString())
                ->sum('total_cost');

            $monthlySpend = (float) DB::table('usage_daily_aggregates')
                ->where('tenant_id', $tenantId)
                ->whereBetween('date', [now()->copy()->startOfMonth()->toDateString(), now()->toDateString()])
                ->sum('total_cost');
        }

        $budget = null;
        if (Schema::hasTable('cost_budgets')) {
            $budget = DB::table('cost_budgets')->where('tenant_id', $tenantId)->first();
        }

        $atlasInferenceDaily = 0.0;
        $atlasInferenceMonthly = 0.0;

        if (Schema::hasTable('usage_daily_aggregates')) {
            $atlasInferenceDaily = (float) DB::table('usage_daily_aggregates')
                ->where('tenant_id', $tenantId)
                ->where('resource_type', 'atlas_inference')
                ->whereDate('date', now()->toDateString())
                ->sum('total_cost');

            $atlasInferenceMonthly = (float) DB::table('usage_daily_aggregates')
                ->where('tenant_id', $tenantId)
                ->where('resource_type', 'atlas_inference')
                ->whereBetween('date', [now()->copy()->startOfMonth()->toDateString(), now()->toDateString()])
                ->sum('total_cost');
        }

        // Platform subscription + usage-vs-allowance (new billing model). Left
        // null for tenants not yet migrated onto a tenant_subscriptions row.
        $subscription = null;
        $usageAllowance = null;

        if (Schema::hasTable('tenant_subscriptions')) {
            $sub = TenantSubscription::forTenant($tenantId)->live()->latest()->first();

            if ($sub) {
                $plan = Plan::find($sub->plan_id);
                $subscription = [
                    'plan_id' => $sub->plan_id,
                    'plan_name' => $plan?->name,
                    'status' => $sub->status,
                    'in_trial' => $sub->inTrial(),
                    'trial_ends_at' => $sub->trial_ends_at?->toIso8601String(),
                    'current_period_end' => $sub->current_period_end?->toIso8601String(),
                    'cancel_at_period_end' => $sub->cancel_at_period_end,
                    'entitlements' => $plan?->entitlements,
                ];

                if ($plan) {
                    $meteredCents = (int) round($monthlySpend * 100);
                    $projectedCents = UsageBilling::projectToMonthEnd($meteredCents, (int) now()->day, (int) now()->daysInMonth);

                    $usageAllowance = [
                        'platform_fee_cents' => $plan->monthly_fee_cents,
                        'included_usage_cents' => $plan->included_usage_cents,
                        'metered_usage_cents' => $meteredCents,
                        'projected_usage_cents' => $projectedCents,
                        'projected_overage_cents' => UsageBilling::overageCents($projectedCents, $plan->included_usage_cents, $plan->usage_margin_pct),
                        'usage_margin_pct' => $plan->usage_margin_pct,
                    ];
                }
            }
        }

        return response()->json([
            'data' => [
                'tenant_id' => $tenantId,
                'plan' => [
                    'id' => $tenant->plan,
                    'name' => $this->planDisplayName($tenant->plan),
                    'status' => $tenant->status,
                    'limits' => $limits,
                ],
                'trial' => [
                    'ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                    'in_trial' => $tenant->isInTrial(),
                ],
                'subscribed_at' => $tenant->subscribed_at?->toIso8601String(),
                'spend' => [
                    'daily_usd' => round($dailySpend, 4),
                    'monthly_usd' => round($monthlySpend, 4),
                    'atlas_inference_daily_usd' => round($atlasInferenceDaily, 4),
                    'atlas_inference_monthly_usd' => round($atlasInferenceMonthly, 4),
                ],
                'budget' => $budget ? [
                    'daily_limit' => (float) $budget->daily_limit,
                    'monthly_limit' => (float) $budget->monthly_limit,
                    'alert_threshold' => (float) $budget->alert_threshold,
                    'action_at_limit' => $budget->action_at_limit,
                ] : null,
                'subscription' => $subscription,
                'usage_allowance' => $usageAllowance,
            ],
        ]);
    }

    private function planDisplayName(string $plan): string
    {
        return match ($plan) {
            'free', 'starter' => 'Starter',
            'growth', 'pro' => 'Growth',
            'enterprise' => 'Enterprise',
            default => ucfirst($plan),
        };
    }
}
