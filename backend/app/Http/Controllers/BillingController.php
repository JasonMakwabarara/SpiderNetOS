<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Billing\UsageBilling;
use App\Services\Integrations\DodoPaymentsAdapter;
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

    /**
     * POST /api/billing/subscribe {plan_id}
     * Upgrades an existing live subscription in place (Dodo change-plan), or
     * starts a new subscription checkout and returns a checkout_url.
     */
    public function subscribe(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'plan_id' => 'required|string|exists:plans,id',
        ]);

        $plan = Plan::find($validated['plan_id']);
        if ($plan->is_custom) {
            return response()->json(['message' => 'Enterprise plans are set up with our team.', 'contact_sales' => true], 422);
        }

        $productId = (string) ($plan->dodo_product_id ?? '');
        if ($productId === '') {
            return response()->json(['message' => 'This plan is not available for self-serve checkout yet.'], 422);
        }

        $adapter = new DodoPaymentsAdapter((array) config('services.dodo'));

        $sub = TenantSubscription::forTenant($tenant->id)
            ->where('status', '!=', 'cancelled')
            ->latest()
            ->first();

        // Already subscribed with a provider subscription → switch plan in place.
        if ($sub && $sub->dodo_subscription_id && $sub->isLive()) {
            if ($sub->plan_id === $plan->id) {
                return response()->json(['message' => 'Already on this plan.', 'plan_id' => $plan->id], 409);
            }
            try {
                $adapter->changePlan($sub->dodo_subscription_id, $productId);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Could not change plan: '.$e->getMessage()], 502);
            }
            $sub->update(['plan_id' => $plan->id]);

            return response()->json(['data' => ['changed' => true, 'plan_id' => $plan->id]]);
        }

        // Otherwise (re)start a checkout for a fresh subscription.
        if (! $sub) {
            $sub = new TenantSubscription(['tenant_id' => $tenant->id]);
        }
        $sub->plan_id = $plan->id;
        $sub->status = 'pending';
        $sub->save();

        try {
            $session = $adapter->createSubscriptionCheckout(
                $productId,
                ['tenant_id' => $tenant->id, 'tenant_subscription_id' => $sub->id, 'plan_id' => $plan->id],
                url('/billing?subscribe=success&plan='.$plan->id),
                url('/billing?subscribe=cancelled'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not start checkout: '.$e->getMessage()], 502);
        }

        return response()->json(['data' => [
            'subscription_id' => $sub->id,
            'checkout_url' => $session['checkout_url'] ?? $session['payment_link'] ?? null,
        ]]);
    }

    /**
     * POST /api/billing/cancel — cancels at period end.
     */
    public function cancel(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $sub = TenantSubscription::forTenant($tenant->id)->live()->latest()->first();
        if (! $sub) {
            return response()->json(['message' => 'No active subscription to cancel.'], 404);
        }

        if ($sub->dodo_subscription_id) {
            try {
                (new DodoPaymentsAdapter((array) config('services.dodo')))
                    ->cancelSubscription($sub->dodo_subscription_id, atPeriodEnd: true);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Could not cancel: '.$e->getMessage()], 502);
            }
        }

        $sub->update(['cancel_at_period_end' => true]);

        return response()->json(['data' => [
            'status' => $sub->status,
            'cancel_at_period_end' => true,
            'effective_at' => $sub->current_period_end?->toIso8601String(),
        ]]);
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
