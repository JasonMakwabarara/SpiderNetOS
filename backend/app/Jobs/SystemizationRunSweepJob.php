<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BusinessProcess;
use App\Services\Systemization\ProcessRunRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles scheduled runs into the systemization feedback loop.
 *
 * Manual runs (POST .../run) record their outcome inline. Scheduled runs
 * go through DispatchScheduledFlowsJob → ExecuteFlowJob, which knows
 * nothing about processes — this sweep finds each automated process's
 * newest settled execution and feeds it to ProcessRunRecorder, so
 * schedules drive the same pass/fail/escalation accounting as buttons.
 */
class SystemizationRunSweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(ProcessRunRecorder $recorder): void
    {
        $processes = BusinessProcess::query()
            ->whereNotNull('flow_id')
            ->get();

        foreach ($processes as $process) {
            $latest = DB::table('flow_executions')
                ->where('tenant_id', (string) $process->tenant_id)
                ->where('flow_id', (string) $process->flow_id)
                ->whereIn('status', ['completed', 'failed'])
                ->orderByDesc('started_at')
                ->first(['id', 'status', 'errors']);

            if (! $latest || $latest->id === $process->last_execution_id) {
                continue; // nothing new to account for
            }

            try {
                $recorder->record($process, [
                    'execution_id' => (string) $latest->id,
                    'status' => (string) $latest->status,
                    'errors' => json_decode((string) ($latest->errors ?? 'null'), true),
                ]);
            } catch (\Throwable $e) {
                Log::error('SystemizationRunSweepJob: write-back failed', [
                    'process_id' => (string) $process->id,
                    'execution_id' => (string) $latest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
