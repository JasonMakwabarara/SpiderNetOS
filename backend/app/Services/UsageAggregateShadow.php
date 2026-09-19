<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UsageAggregateShadow
 *
 * During the 48-hour shadow window (flag atlas.usage_aggregates_v2.shadow=on)
 * this service compares the incoming canonical values against whatever is
 * currently stored in usage_daily_aggregates and persists any discrepancy
 * into usage_shadow_diffs.
 *
 * The cutover gate query (§11.2) must return 0 open diffs for ≥ 24 consecutive
 * hours before the atlas.usage_aggregates_v2.cutover flag can be flipped:
 *
 *   SELECT COUNT(*) FROM usage_shadow_diffs
 *   WHERE detected_at >= now() - interval '24 hours'
 *     AND resolved_at IS NULL;
 *
 * Methods
 *   diff()       — called by AggregateUsageJob after each upsert
 *   gateStatus() — returns ['open_diffs' => int, 'ready_for_cutover' => bool]
 */
class UsageAggregateShadow
{
    /**
     * Compare canonical values against what is currently stored and log any
     * discrepancy into usage_shadow_diffs.
     *
     * If existingCalls / existingCost are null the row is brand-new; that is
     * not a diff (it is a normal insert) so we skip logging.
     */
    public function diff(
        string $tenantId,
        string $date,
        string $resourceType,
        ?int $existingCalls,
        int $newCalls,
        ?float $existingCost,
        float $newCost,
    ): void {
        if ($existingCalls === null) {
            // Brand-new row — not a mismatch
            return;
        }

        $diffs = [];

        if ($existingCalls !== $newCalls) {
            $diffs[] = 'count_mismatch';
        }

        // Allow for floating-point rounding (< 0.000001 is noise)
        if (abs($existingCost - $newCost) > 0.000001) {
            $diffs[] = 'cost_mismatch';
        }

        foreach ($diffs as $kind) {
            try {
                DB::table('usage_shadow_diffs')->insert([
                    'tenant_id' => $tenantId,
                    'date' => $date,
                    'resource_type' => $resourceType,
                    'legacy_request_count' => $existingCalls,
                    'canonical_total_calls' => $newCalls,
                    'legacy_total_cost' => $existingCost,
                    'canonical_total_cost' => $newCost,
                    'diff_kind' => $kind,
                    'detected_at' => now(),
                    'resolved_at' => null,
                ]);

                Log::warning("[UsageAggregateShadow] {$kind} detected", [
                    'tenant_id' => $tenantId,
                    'date' => $date,
                    'resource_type' => $resourceType,
                    'existing' => ['calls' => $existingCalls, 'cost' => $existingCost],
                    'new' => ['calls' => $newCalls, 'cost' => $newCost],
                ]);
            } catch (\Throwable $e) {
                // Never block the primary write path
                Log::error('[UsageAggregateShadow] Failed to log diff: '.$e->getMessage());
            }
        }
    }

    /**
     * Returns the shadow-gate status.
     *
     * 'ready_for_cutover' is true when there have been zero unresolved diffs
     * in the past 24 hours — the hard gate before flipping the cutover flag.
     */
    public function gateStatus(): array
    {
        $openDiffs = (int) DB::table('usage_shadow_diffs')
            ->where('detected_at', '>=', now()->subHours(24))
            ->whereNull('resolved_at')
            ->count();

        $totalDiffs = (int) DB::table('usage_shadow_diffs')
            ->whereNull('resolved_at')
            ->count();

        return [
            'open_diffs_last_24h' => $openDiffs,
            'total_open_diffs' => $totalDiffs,
            'ready_for_cutover' => $openDiffs === 0,
        ];
    }

    /**
     * Mark all open diffs as resolved (used after investigation confirms
     * the source of a transient mismatch).
     */
    public function resolveAll(): int
    {
        return DB::table('usage_shadow_diffs')
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }
}
