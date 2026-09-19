<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CostGovernor
{
    private const REDIS_KEY_DAILY = 'cost:daily:%s:%s';

    private const REDIS_KEY_MONTHLY = 'cost:monthly:%s:%s';

    public function canExecute(string $tenantId, float $estimatedCost = 0): array
    {
        $budget = $this->getBudget($tenantId);
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        $dailySpent = $this->getDailySpend($tenantId, $today);
        $monthlySpent = $this->getMonthlySpend($tenantId, $month);

        $dailyRemaining = $budget['daily_limit'] - $dailySpent;
        $monthlyRemaining = $budget['monthly_limit'] - $monthlySpent;

        // Pre-allocation check: block BEFORE consuming resources
        $dailyAfterEstimate = $dailyRemaining - $estimatedCost;
        $monthlyAfterEstimate = $monthlyRemaining - $estimatedCost;

        $allowed = $dailyAfterEstimate >= 0 && $monthlyAfterEstimate >= 0;
        $degraded = false;
        $action = 'allow';

        // Check if we should degrade instead of block
        if (! $allowed && $budget['action_at_limit'] === 'degrade') {
            $allowed = true;
            $degraded = true;
            $action = 'degrade';
        }

        // Alert threshold check
        $alertTriggered = false;
        if ($dailySpent >= $budget['daily_limit'] * $budget['alert_threshold']) {
            $alertTriggered = true;
        }

        return [
            'allowed' => $allowed,
            'degraded' => $degraded,
            'action' => $action,
            'estimated_cost' => $estimatedCost,
            'daily_remaining' => max(0, $dailyRemaining),
            'monthly_remaining' => max(0, $monthlyRemaining),
            'daily_remaining_after_estimate' => max(0, $dailyAfterEstimate),
            'monthly_remaining_after_estimate' => max(0, $monthlyAfterEstimate),
            'daily_limit' => $budget['daily_limit'],
            'monthly_limit' => $budget['monthly_limit'],
            'alert_triggered' => $alertTriggered,
            'alert_threshold' => $budget['alert_threshold'],
        ];
    }

    public function recordUsage(
        string $tenantId,
        string $resourceType,
        float $cost,
        array $metadata = []
    ): void {
        $today = now()->toDateString();
        $month = now()->format('Y-m');

        // Atomic increment in Redis — best-effort. The event_log append below
        // is the durable record; Redis is the hot-path counter.
        try {
            Redis::incrbyfloat(sprintf(self::REDIS_KEY_DAILY, $tenantId, $today), $cost);
            Redis::incrbyfloat(sprintf(self::REDIS_KEY_MONTHLY, $tenantId, $month), $cost);

            // Set expiry on Redis keys (30 days for daily, 2 years for monthly)
            Redis::expire(sprintf(self::REDIS_KEY_DAILY, $tenantId, $today), 86400 * 30);
            Redis::expire(sprintf(self::REDIS_KEY_MONTHLY, $tenantId, $month), 86400 * 730);
        } catch (\Throwable $e) {
            Log::warning('CostGovernor: Redis unavailable, counters not incremented', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }

        // Emit event for projection
        $eventStore = app(EventStore::class);
        $eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'usage',
            aggregateId: (string) Str::uuid(),
            eventType: 'usage.recorded',
            payload: [
                'resource_type' => $resourceType,
                'cost_usd' => $cost,
                'metadata' => $metadata,
                'record_id' => (string) Str::uuid(),
            ],
            metadata: ['governor_check' => true]
        );

        // Check for budget exceeded
        $status = $this->canExecute($tenantId);
        if ($status['daily_remaining'] <= 0 || $status['monthly_remaining'] <= 0) {
            $eventStore->append(
                tenantId: $tenantId,
                aggregateType: 'usage',
                aggregateId: (string) Str::uuid(),
                eventType: 'usage.budget_exceeded',
                payload: [
                    'daily_remaining' => $status['daily_remaining'],
                    'monthly_remaining' => $status['monthly_remaining'],
                    'action_taken' => $status['action'],
                ],
                metadata: ['severity' => 'critical']
            );
        }
    }

    public function selectModel(string $tenantId, string $preferredModel, array $fallbackChain): string
    {
        $status = $this->canExecute($tenantId);

        // If degraded mode, force cheaper model
        if ($status['degraded']) {
            return $this->findCheapestAvailable($fallbackChain);
        }

        // If preferred model would exceed budget, fallback
        $preferredCost = $this->estimateModelCost($preferredModel);
        if ($preferredCost > $status['daily_remaining']) {
            foreach ($fallbackChain as $fallback) {
                if ($this->estimateModelCost($fallback) <= $status['daily_remaining']) {
                    return $fallback;
                }
            }
            // No affordable model found
            throw new \RuntimeException('No affordable model available for tenant');
        }

        return $preferredModel;
    }

    private function getBudget(string $tenantId): array
    {
        $budget = DB::table('cost_budgets')
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $budget) {
            // Default budget from environment
            return [
                'daily_limit' => (float) env('COST_CEILING_DEFAULT', 10.00),
                'monthly_limit' => (float) env('COST_CEILING_DEFAULT', 10.00) * 10,
                'alert_threshold' => 0.80,
                'action_at_limit' => 'block',
            ];
        }

        return [
            'daily_limit' => (float) $budget->daily_limit,
            'monthly_limit' => (float) $budget->monthly_limit,
            'alert_threshold' => (float) $budget->alert_threshold,
            'action_at_limit' => $budget->action_at_limit,
        ];
    }

    private function getDailySpend(string $tenantId, string $date): float
    {
        try {
            $key = sprintf(self::REDIS_KEY_DAILY, $tenantId, $date);

            return (float) Redis::get($key) ?: 0;
        } catch (\Throwable $e) {
            // A Redis outage must degrade to the DB projection, not 500 every
            // request via EnforcePlanLimits (budget guard, not a security gate).
            return $this->aggregateSpendFallback($tenantId, $date, $date);
        }
    }

    private function getMonthlySpend(string $tenantId, string $month): float
    {
        try {
            $key = sprintf(self::REDIS_KEY_MONTHLY, $tenantId, $month);

            return (float) Redis::get($key) ?: 0;
        } catch (\Throwable $e) {
            $start = $month.'-01';
            $end = now()->toDateString();

            return $this->aggregateSpendFallback($tenantId, $start, $end);
        }
    }

    private function aggregateSpendFallback(string $tenantId, string $from, string $to): float
    {
        try {
            if (! Schema::hasTable('usage_daily_aggregates')) {
                return 0.0;
            }

            return (float) DB::table('usage_daily_aggregates')
                ->where('tenant_id', $tenantId)
                ->whereBetween('date', [$from, $to])
                ->sum('total_cost');
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function estimateModelCost(string $model): float
    {
        $costs = config('services.model_costs', [
            'gpt-4' => 0.03,
            'gpt-4o' => 0.005,
            'gpt-4o-mini' => 0.00015,
            'claude-3-opus' => 0.015,
            'claude-3-sonnet' => 0.003,
            'claude-3-haiku' => 0.00025,
        ]);

        return $costs[$model] ?? 0.01;
    }

    private function findCheapestAvailable(array $models): string
    {
        $costs = config('services.model_costs', []);

        usort($models, function ($a, $b) use ($costs) {
            return ($costs[$a] ?? PHP_FLOAT_MAX) <=> ($costs[$b] ?? PHP_FLOAT_MAX);
        });

        return $models[0] ?? 'gpt-4o-mini';
    }
}
