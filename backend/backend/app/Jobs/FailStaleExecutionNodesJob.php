<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\DagExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Watchdog: no DAG node may stay `running` forever.
 *
 * Agent-dispatched nodes complete asynchronously; if the worker dies,
 * never picks the message up, or the callback is lost, the node — and
 * with it the whole execution — used to hang indefinitely. Failing it
 * lets downstream accountability (run write-back, escalation) engage
 * instead of silently stalling.
 */
class FailStaleExecutionNodesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DagExecutionService $executions): void
    {
        $staleMinutes = max(1, (int) config('spidernet.node_stale_after_minutes', 15));
        $cutoff = now()->subMinutes($staleMinutes);

        $staleNodes = DB::table('execution_dag_nodes')
            ->where('status', 'running')
            ->where('started_at', '<', $cutoff)
            ->limit(100)
            ->get(['execution_id', 'node_id', 'started_at']);

        foreach ($staleNodes as $node) {
            try {
                $executions->failNode(
                    (string) $node->execution_id,
                    (string) $node->node_id,
                    "Watchdog: node exceeded {$staleMinutes} minutes in running state (started {$node->started_at}).",
                );

                Log::warning('FailStaleExecutionNodesJob: stale node failed', [
                    'execution_id' => $node->execution_id,
                    'node_id' => $node->node_id,
                ]);
            } catch (\Throwable $e) {
                Log::error('FailStaleExecutionNodesJob: could not fail node', [
                    'execution_id' => $node->execution_id,
                    'node_id' => $node->node_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
