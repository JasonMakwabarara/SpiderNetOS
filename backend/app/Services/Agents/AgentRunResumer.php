<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Jobs\ResumeAgentRunJob;
use App\Models\AgentRun;
use App\Services\EventStore;
use Illuminate\Support\Facades\Log;

/**
 * Approval hook for resource type `agent_tool_call` (config/approvals.php).
 * The approval's resource_id is the run id; the pending call sits in
 * run.state.pending_tool_call. Records the human's decision and queues
 * ResumeAgentRunJob — granted runs execute the call with the ladder
 * bypassed, rejected runs continue with a `rejected_by_human` tool result
 * (agentic) or fail their post action (single_shot).
 */
final class AgentRunResumer
{
    public function __construct(private readonly EventStore $events) {}

    public function onApprovalResolved(string $tenantId, string $resourceId, bool $granted, string $response = ''): void
    {
        $run = AgentRun::forTenant($tenantId)->find($resourceId);
        if ($run === null || $run->status !== AgentRun::STATUS_WAITING_APPROVAL) {
            Log::debug('agent_tool_call approval resolved for a run that is not waiting', ['run_id' => $resourceId, 'status' => $run?->status]);

            return;
        }

        $state = (array) ($run->state ?? []);
        $pending = (array) ($state['pending_tool_call'] ?? []);
        if ($pending === []) {
            return;
        }

        $pending['decision'] = $granted ? 'approved' : 'rejected';
        $pending['response'] = mb_substr($response, 0, 1000);
        $pending['decided_at'] = now()->toIso8601String();
        $state['pending_tool_call'] = $pending;
        $run->forceFill(['state' => $state])->save();

        $this->events->append($tenantId, 'agent_run', (string) $run->id, 'agent.run.approval_resolved', [
            'run_id' => $run->id,
            'skill_slug' => $run->skill_slug,
            'tool' => $pending['tool'] ?? null,
            'approval_id' => $pending['approval_id'] ?? null,
            'granted' => $granted,
            'response' => $response,
        ], ['runtime' => 'php_skill']);

        ResumeAgentRunJob::dispatch((string) $run->id, $tenantId)->onQueue((string) config('agents.queue', 'agents'));
    }
}
