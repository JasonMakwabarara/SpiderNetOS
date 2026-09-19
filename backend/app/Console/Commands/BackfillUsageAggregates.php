<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BackfillUsageAggregates
 *
 * Re-aggregates usage data from event_log into usage_daily_aggregates for a
 * date range, backfilling total_calls, total_tokens, total_cost, calculated_at.
 *
 * Run after the reconciliation migration to populate existing rows:
 *
 *   php artisan usage:backfill --from=2026-01-01 --to=2026-04-17
 *   php artisan usage:backfill --from=2026-01-01 --to=2026-04-17 --dry-run
 *
 * Options
 *   --from      Start date (inclusive, Y-m-d). Default: 30 days ago.
 *   --to        End date (inclusive, Y-m-d).   Default: yesterday.
 *   --tenant    Only backfill a specific tenant UUID.
 *   --dry-run   Log what would be written without committing.
 *   --chunk     Rows per transaction (default 500).
 */
class BackfillUsageAggregates extends Command
{
    protected $signature = 'usage:backfill
                            {--from=          : Start date Y-m-d}
                            {--to=            : End date Y-m-d}
                            {--tenant=        : Restrict to a single tenant UUID}
                            {--dry-run        : Log changes without committing}
                            {--chunk=500      : Rows per DB transaction}';

    protected $description = 'Backfill usage_daily_aggregates from event_log (canonical schema)';

    public function handle(): int
    {
        $from = $this->option('from') ?? now()->subDays(30)->toDateString();
        $to = $this->option('to') ?? now()->subDay()->toDateString();
        $tenant = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) ($this->option('chunk') ?: 500);

        $this->info("Backfilling {$from} → {$to}".($tenant ? " (tenant: {$tenant})" : '').($dryRun ? ' [DRY RUN]' : ''));

        $tenants = $tenant
            ? collect([$tenant])
            : DB::table('tenants')->where('status', 'active')->pluck('id');

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');

            return self::SUCCESS;
        }

        $written = 0;
        $skipped = 0;

        foreach ($tenants as $tid) {
            [$w, $s] = $this->backfillTenant($tid, $from, $to, $dryRun, $chunk);
            $written += $w;
            $skipped += $s;
        }

        $this->info("Done. Written: {$written}, Skipped (no data): {$skipped}");

        return self::SUCCESS;
    }

    private function backfillTenant(
        string $tenantId,
        string $from,
        string $to,
        bool $dryRun,
        int $chunk,
    ): array {
        $written = 0;
        $skipped = 0;

        $costCeiling = (float) DB::table('cost_budgets')
            ->where('tenant_id', $tenantId)
            ->value('daily_limit') ?: (float) env('COST_CEILING_DEFAULT', 10.00);

        // Iterate each day in the range
        $current = Carbon::parse($from);
        $end = Carbon::parse($to);

        $buffer = [];

        while ($current->lte($end)) {
            $date = $current->toDateString();

            $rows = DB::table('event_log')
                ->where('tenant_id', $tenantId)
                ->where('event_type', 'usage.recorded')
                ->whereDate('occurred_at', $date)
                ->selectRaw("payload->>'resource_type' as resource_type")
                ->selectRaw("SUM((payload->>'cost_usd')::numeric) as total_cost")
                ->selectRaw(
                    "SUM(COALESCE((payload->>'tokens_input')::integer,0) + COALESCE((payload->>'tokens_output')::integer,0)) as total_tokens"
                )
                ->selectRaw('COUNT(*) as total_calls')
                ->groupByRaw("payload->>'resource_type'")
                ->get();

            if ($rows->isEmpty()) {
                $skipped++;
                $current->addDay();

                continue;
            }

            foreach ($rows as $row) {
                $buffer[] = [
                    'tenantId' => $tenantId,
                    'date' => $date,
                    'resourceType' => $row->resource_type ?? 'unclassified',
                    'totalCost' => (float) $row->total_cost,
                    'totalCalls' => (int) $row->total_calls,
                    'totalTokens' => (int) $row->total_tokens,
                    'costCeiling' => $costCeiling,
                ];
            }

            // Flush when chunk size reached
            if (count($buffer) >= $chunk) {
                $written += $this->flush($buffer, $dryRun);
                $buffer = [];
            }

            $current->addDay();
        }

        // Flush remainder
        if (! empty($buffer)) {
            $written += $this->flush($buffer, $dryRun);
        }

        return [$written, $skipped];
    }

    private function flush(array $buffer, bool $dryRun): int
    {
        if ($dryRun) {
            foreach ($buffer as $r) {
                $this->line(sprintf(
                    '  [DRY] %s %s %s  calls=%d tokens=%d cost=%.6f',
                    $r['tenantId'], $r['date'], $r['resourceType'],
                    $r['totalCalls'], $r['totalTokens'], $r['totalCost']
                ));
            }

            return count($buffer);
        }

        DB::transaction(function () use ($buffer) {
            foreach ($buffer as $r) {
                DB::table('usage_daily_aggregates')->updateOrInsert(
                    [
                        'tenant_id' => $r['tenantId'],
                        'date' => $r['date'],
                        'resource_type' => $r['resourceType'],
                    ],
                    [
                        'total_cost' => $r['totalCost'],
                        'total_calls' => $r['totalCalls'],
                        'total_tokens' => $r['totalTokens'],
                        'cost_ceiling' => $r['costCeiling'],
                        'calculated_at' => now(),
                    ],
                );
            }
        });

        return count($buffer);
    }
}
