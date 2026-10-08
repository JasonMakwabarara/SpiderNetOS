<?php

declare(strict_types=1);

namespace App\Services\Agents\Exceptions;

/**
 * Control-flow signal: a tool call needs a human, the run has been moved to
 * waiting_approval and its loop state persisted. ResumeAgentRunJob picks it
 * up when the approval resolves. Not an error.
 */
class RunParkedException extends AgentRuntimeException
{
    public function __construct(public readonly string $approvalId, string $tool)
    {
        parent::__construct("Run parked on approval [{$approvalId}] for tool {$tool}.", 'run_waiting_approval', 202, ['approval_id' => $approvalId, 'tool' => $tool]);
    }
}
