<?php

namespace App\Jobs;

use App\Services\FeatureFlag;
use App\Services\UsageAggregateShadow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * AggregateUsageJob
 *
 * Reads per-tenant daily spend counters from Redis and per-resource-type
 * breakdowns from event_log, then upserts canonical rows into
 * `usage_daily_aggregates`.
 *
 * Canonical columns (from the migration source of truth):
 *   total_calls    — number of API/inference requests
 *   total_tokens   — sum of tokens_input + tokens_output
 *   total_cost     — sum of cost_usd
 *   cost_ceiling   — tenant's daily limit at the time of aggregation
 *   calculated_at  — timestamp this row was last computed
 *
 * Feature-flag behaviour:
 *   atlas.usage_aggregates_v2         = off   → nothing (job is a no-op in legacy envs)
 *   atlas.usage_aggregates_v2         = on
 *     atlas.usage_aggregates_v2.shadow = on   → dual-write: canonial + diff-log, no cutover
 *     atlas.usage_aggregates_v2.cutover = on  → canonical write only (no legacy path)
 *
 * NOTE: All JSON access uses Postgres jsonb operators ( payload->>'key' ).
 * No MySQL-specific functions are used.
 */
class AggregateUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    private string $targetDate;

    /**
     * @param  string|null  $date  ISO date override (Y-m-d). Defaults to yesterday.
     */
    public function __construct(?string $date = null)
    {
        $this->targetDate = $date ?? now()->subDay()->toDateString();
    }

    public function handle(): void
    {
        if (! FeatureFlag::on('atlas.usage_aggregates_v2')) {
            Log::info('[AggregateUsage] v2 flag is off — skipping.');

            return;
        }

        $tenants = DB::table('tenants')
            ->where('status', 'active')
            ->pluck('id');

        if ($tenants->isEmpty()) {
            Log::info('[AggregateUsage] No active tenants — skipping.');

            return;
        }

        $rowsWritten = 0;
        $isShadow = FeatureFlag::on('atlas.usage_aggregates_v2.shadow');
        $isCutover = FeatureFlag::on('atlas.usage_aggregates_v2.cutover');

        foreach ($tenants as $tenantId) {
            $rowsWritten += $this->aggregateForTenant($tenantId, $isShadow, $isCutover);
        }

        Log::info("[AggregateUsage] Wrote {$rowsWritten} aggregate rows for {$this->targetDate}.");
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function aggregateForTenant(
        string $tenantId,
        bool $isShadow,
        bool $isCutover,
    ): int {
        // 1. Total daily spend from Redis counter
        $redisKey = sprintf('cost:daily:%s:%s', $tenantId, $this->targetDate);
        $totalSpend = (float) (Redis::get($redisKey) ?: 0.0);

        // 2. Tenant's current daily cost ceiling (for the cost_ceiling column)
        $costCeiling = (float) DB::table('cost_budgets')
            ->where('tenant_id', $tenantId)
            ->value('daily_limit') ?: (float) env('COST_CEILING_DEFAULT', 10.00);

        // 3. Per-resource-type breakdown from event_log using Postgres jsonb operators
        $breakdown = DB::table('event_log')
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'usage.recorded')
            ->whereDate('occurred_at', $this->targetDate)
            ->selectRaw("payload->>'resource_type' as resource_type")
            ->selectRaw("SUM((payload->>'cost_usd')::numeric) as total_cost")
            ->selectRaw(
                "SUM(COALESCE((payload->>'tokens_input')::integer, 0) + COALESCE((payload->>'tokens_output')::integer, 0)) as total_tokens"
            )
            ->selectRaw('COUNT(*) as total_calls')
            ->groupByRaw("payload->>'resource_type'")
            ->get();

        if ($breakdown->isEmpty()) {
            if ($totalSpend > 0) {
                $this->upsertRow($tenantId, 'unclassified', $totalSpend, 0, 0, $costCeiling, $isShadow);
                $this->emitPersistEvent($tenantId, 'unclassified', $totalSpend, 0, 0);

                return 1;
            }

            return 0;
        }

        $rows = 0;

        foreach ($breakdown as $row) {
            $resourceType = $row->resource_type ?? 'unclassified';
            $cost = (float) $row->total_cost;
            $calls = (int) $row->total_calls;
            $tokens = (int) $row->total_tokens;

            $this->upsertRow($tenantId, $resourceType, $cost, $calls, $tokens, $costCeiling, $isShadow);
            $this->emitPersistEvent($tenantId, $resourceType, $cost, $calls, $tokens);
            $rows++;
        }

        return $rows;
    }

    /**
     * Upsert a single canonical aggregate row (idempotent on re-run).
     *
     * When shadow mode is active, UsageAggregateShadow also logs any
     * differences versus what was previously stored.
     */
    private function upsertRow(
        string $tenantId,
        string $resourceType,
        float $totalCost,
        int $totalCalls,
        int $totalTokens,
        float $costCeiling,
        bool $isShadow,
    ): void {
        $matchKey = [
            'tenant_id' => $tenantId,
            'date' => $this->targetDate,
            'resource_type' => $resourceType,
        ];

        $values = [
            'total_cost' => $totalCost,
            'total_calls' => $totalCalls,
            'total_tokens' => $totalTokens,
            'cost_ceiling' => $costCeiling,
            'calculated_at' => now(),
        ];

        if ($isShadow) {
            // In shadow mode: capture existing row for diff comparison
            $existing = DB::table('usage_daily_aggregates')
                ->where($matchKey)
                ->first(['total_calls', 'total_cost']);

            app(UsageAggregateShadow::class)->diff(
                $tenantId,
                $this->targetDate,
                $resourceType,
                existingCalls: $existing ? (int) $existing->total_calls : null,
                newCalls: $totalCalls,
                existingCost: $existing ? (float) $existing->total_cost : null,
                newCost: $totalCost,
            );
        }

        DB::table('usage_daily_aggregates')->updateOrInsert($matchKey, $values);
    }

    /**
     * Emit a usage.aggregate.persisted event into event_log for Atlas RL.
     */
    private function emitPersistEvent(
        string $tenantId,
        string $resourceType,
        float $totalCost,
        int $totalCalls,
        int $totalTokens,
    ): void {
        try {
            DB::table('event_log')->insert([
                'id' => Str::uuid(),
                'tenant_id' => $tenantId,
                'aggregate_type' => 'usage_aggregate',
                'aggregate_id' => $tenantId.':'.$this->targetDate.':'.$resourceType,
                'event_type' => 'usage.aggregate.persisted',
                'sequence_num' => 0,
                'occurred_at' => now(),
                'payload' => json_encode([
                    'schema_version' => '2.0.0',
                    'tenant_id' => $tenantId,
                    'date' => $this->targetDate,
                    'resource_type' => $resourceType,
                    'total_calls' => $totalCalls,
                    'total_tokens' => $totalTokens,
                    'total_cost' => $totalCost,
                    'calculated_at' => now()->toIso8601String(),
                    'atlas' => [
                        'ts_signal' => 'observability_integrity',
                    ],
                ]),
                'metadata' => json_encode([]),
            ]);
        } catch (\Throwable $e) {
            // Never let telemetry block the primary write
            Log::warning('[AggregateUsage] Failed to emit persist event: '.$e->getMessage());
        }
    }
}
