<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\AgentRunUpdated;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\AgentWorkspace;
use App\Services\EventStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Every ten minutes: a claimed/running run whose lease expired (worker
 * died, job timed out without finalising) is marked failed so its tenant
 * slot and workspace are freed. Retry stays explicit.
 */
class FailStaleAgentRunsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(EventStore $events): int
    {
        $count = 0;

        AgentRun::stale()->orderBy('lease_expires_at')->limit(200)->get()->each(function (AgentRun $run) use ($events, &$count) {
            $updated = AgentRun::query()
                ->whereKey($run->id)
                ->whereIn('status', AgentRun::ACTIVE)
                ->where('lease_expires_at', '<', now())
                ->update([
                    'status' => AgentRun::STATUS_FAILED,
                    'error' => 'lease_expired: worker did not finish before '.$run->lease_expires_at?->toIso8601String(),
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return;
            }
            $count++;
            $run->refresh();

            try {
                AgentRunStep::create([
                    'tenant_id' => $run->tenant_id,
                    'run_id' => $run->id,
                    'seq' => (int) AgentRunStep::where('run_id', $run->id)->max('seq') + 1,
                    'kind' => AgentRunStep::KIND_ERROR,
                    'name' => 'lease_expired',
                    'input' => [],
                    'output' => ['claimed_by' => $run->claimed_by],
                    'status' => AgentRunStep::STATUS_FAILED,
                ]);
                $events->append((string) $run->tenant_id, 'agent_run', (string) $run->id, 'agent.run.failed', [
                    'run_id' => $run->id, 'skill_slug' => $run->skill_slug, 'workspace_id' => $run->workspace_id,
                    'agent_id' => $run->agent_id, 'error' => $run->error, 'code' => 'lease_expired', 'status' => AgentRun::STATUS_FAILED,
                ], ['runtime' => 'php_skill']);
            } catch (\Throwable $e) {
                Log::warning('stale run trace failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            }

            if ($run->workspace_id) {
                AgentWorkspace::whereKey($run->workspace_id)
                    ->where('status', AgentWorkspace::STATUS_WORKING)
                    ->update(['status' => AgentWorkspace::STATUS_NEEDS_ATTENTION, 'updated_at' => now()]);
            }
            AgentRunUpdated::safeBroadcast($run);
        });

        if ($count > 0) {
            Log::info('stale agent runs failed', ['count' => $count]);
        }

        return $count;
    }
}
