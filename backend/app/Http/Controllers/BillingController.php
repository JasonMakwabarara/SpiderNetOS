<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
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
