<?php

namespace App\Jobs;

use App\Jobs\OpsDivergenceAlertJob;
use App\Services\ReplayDivergenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReplayDivergenceSweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30, 120];

    public function __construct(private readonly int $maxExecutionsPerTenant = 25)
    {
    }

    public function handle(ReplayDivergenceService $replayDivergence): void
    {
        $tenants = DB::table('tenants')
            ->where('status', 'active')
            ->pluck('id');

        foreach ($tenants as $tenantId) {
            $executions = DB::table('flow_executions')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['failed', 'running', 'paused'])
                ->orderByDesc('updated_at')
                ->limit($this->maxExecutionsPerTenant)
                ->pluck('id');

            foreach ($executions as $executionId) {
                try {
                    $report = $replayDivergence->detectDivergence((string) $tenantId, (string) $executionId);

                    // Only a new or changed divergence is worth waking anyone
                    // for: the service hands back the previous report
                    // unchanged while nothing moves.
                    if (($report['status'] ?? 'clean') !== 'clean' && ($report['unchanged'] ?? false) !== true) {
                        Log::warning('Replay divergence detected', [
                            'tenant_id' => (string) $tenantId,
                            'execution_id' => (string) $executionId,
                            'report_id' => $report['id'] ?? null,
                            'divergence_count' => $report['divergence_count'] ?? null,
                        ]);

                        OpsDivergenceAlertJob::dispatch(
                            (string) $tenantId,
                            (string) $executionId,
                            (string) ($report['id'] ?? ''),
                            (int) ($report['divergence_count'] ?? 0)
                        );
                    }
                } catch (\Throwable $e) {
                    Log::error('Replay divergence sweep failed for execution', [
                        'tenant_id' => (string) $tenantId,
                        'execution_id' => (string) $executionId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
