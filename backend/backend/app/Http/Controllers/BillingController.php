<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Payments\DodoPaymentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Billing & plan summary for the Cockpit (tenant-scoped).
 * Checkout + cancellation run through Dodo Payments.
 */
class BillingController extends Controller
{
    public function __construct(private readonly DodoPaymentsService $dodo)
    {
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
            ],
        ]);
    }

    /**
     * GET /api/billing/plans — catalogue for the upgrade UI.
     */
    public function plans(): JsonResponse
    {
        $plans = collect((array) config('dodo.plans', []))
            ->map(fn (array $plan, string $id) => [
                'id' => $id,
                'name' => $plan['label'],
                'price_usd' => $plan['price_usd'],
                'interval' => $plan['interval'],
                'limits' => $plan['limits'],
                'self_serve' => ($plan['product_id'] ?? '') !== '',
            ])
            ->values();

        return response()->json([
            'data' => [
                'gateway' => DodoPaymentsService::GATEWAY_CODE,
                'enabled' => $this->dodo->enabled(),
                'plans' => $plans,
            ],
        ]);
    }

    /**
     * POST /api/billing/checkout {plan} — returns a hosted Dodo checkout URL.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => 'required|string|in:' . implode(',', array_keys((array) config('dodo.plans', []))),
        ]);

        if (! $this->dodo->enabled()) {
            return response()->json([
                'message' => 'Payments are not enabled on this environment.',
            ], 503);
        }

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        try {
            $session = $this->dodo->createCheckoutSession($tenant, $request->user(), $validated['plan']);
        } catch (\RuntimeException $ex) {
            Log::error('billing-> checkout(): ' . $ex->getMessage(), [
                'tenant_id' => (string) $tenant->id,
                'plan' => $validated['plan'],
            ]);

            return response()->json(['message' => 'Checkout could not be started. Try again shortly.'], 502);
        }

        return response()->json([
            'data' => [
                'checkout_url' => $session['checkout_url'],
                'reference' => $session['reference'],
            ],
        ]);
    }

    /**
     * POST /api/billing/cancel — schedule cancellation at period end.
     */
    public function cancel(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        $subscription = Subscription::query()
            ->where('tenant_id', (string) $tenant->id)
            ->where('provider', DodoPaymentsService::GATEWAY_CODE)
            ->where('status', 'active')
            ->latest('created_at')
            ->first();

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription to cancel.'], 404);
        }

        try {
            $this->dodo->cancelSubscription($subscription->provider_subscription_id);
        } catch (\RuntimeException $ex) {
            return response()->json(['message' => $ex->getMessage()], 502);
        }

        $subscription->fill(['cancelled_at' => now()])->save();

        return response()->json([
            'data' => [
                'message' => 'Your subscription stays active until the end of the current billing period.',
                'ends_at' => $subscription->current_period_end?->toIso8601String(),
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
