<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Middleware\TenantRunSlot;
use App\Models\AgentRun;
use App\Services\Agents\AgentRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Executes one agent run on the `agents` queue (ADR-0002 D1). Unique by run
 * id so Horizon never double-runs it; one try — AgentRunner finalises a
 * failed run as `failed` itself and retry is explicit (`POST
 * /agent-runs/{id}/retry`, `agents:run --retry`). Only MetaPlanner::dispatchRun
 * dispatches this job (Hard Rule #2).
 */
class RunSkillJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(
        public readonly string $runId,
        public readonly ?string $tenantId = null,
    ) {
        $this->timeout = max(30, (int) config('agents.job_timeout_seconds', 240));
        $this->uniqueFor = $this->timeout + 60;
        $this->onQueue((string) config('agents.queue', 'agents'));
    }

    public function uniqueId(): string
    {
        return 'agent-run:'.$this->runId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        $tenantId = $this->tenantId ?? (string) AgentRun::query()->whereKey($this->runId)->value('tenant_id');

        return [new TenantRunSlot($tenantId !== '' ? $tenantId : null)];
    }

    public function handle(AgentRunner $runner): void
    {
        $run = AgentRun::find($this->runId);
        if ($run === null || $run->status !== AgentRun::STATUS_QUEUED) {
            return; // cancelled, already claimed by another worker, or gone
        }

        $runner->run($run);
    }
}
