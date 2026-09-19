<?php

declare(strict_types=1);

namespace App\Services\Systemization;

use App\Models\BusinessProcess;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use Illuminate\Support\Facades\Log;

/**
 * Feeds execution results back into the owning process — the literal
 * input → process → output → feedback loop.
 *
 * A pass resets the failure streak. Two consecutive failures escalate:
 * an Approval is opened for a human (the "go to the SOP, then your
 * manager" rule), the process is flagged needs_attention, and the
 * resolution answer becomes a new SOP revision so the fix is permanent.
 */
class ProcessRunRecorder
{
    public const ESCALATION_THRESHOLD = 2;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array{execution_id?: ?string, status?: string, errors?: mixed}  $executionStatus
     */
    public function record(BusinessProcess $process, array $executionStatus): BusinessProcess
    {
        $status = (string) ($executionStatus['status'] ?? 'unknown');

        $runStatus = match ($status) {
            'completed', 'cached' => 'passed',
            'failed' => 'failed',
            'paused' => 'paused', // waiting on a human approval gate — not a failure
            default => 'running',
        };

        $process->last_execution_id = $executionStatus['execution_id'] ?? $process->last_execution_id;
        $process->last_run_at = now();
        $process->last_run_status = $runStatus;

        if ($runStatus === 'passed') {
            $process->consecutive_failures = 0;
            $process->needs_attention = false;
        } elseif ($runStatus === 'failed') {
            $process->consecutive_failures = $process->consecutive_failures + 1;
        }

        $process->save();

        $this->eventStore->append(
            (string) $process->tenant_id,
            'systemization',
            (string) $process->id,
            'systemization.process.run_recorded',
            [
                'process' => $process->name,
                'execution_id' => $process->last_execution_id,
                'run_status' => $runStatus,
                'consecutive_failures' => $process->consecutive_failures,
            ],
        );

        if (
            $runStatus === 'failed'
            && $process->consecutive_failures >= self::ESCALATION_THRESHOLD
            && ! $process->needs_attention
        ) {
            $this->escalate($process, $executionStatus);
        }

        return $process->fresh();
    }

    private function escalate(BusinessProcess $process, array $executionStatus): void
    {
        // The requester is the responsible owner; a process always has one.
        $requesterId = $process->owner_agent_id
            ?? $process->owner_user_id
            ?? (string) $process->id;

        try {
            $approval = $this->approvals->createApproval(
                tenantId: (string) $process->tenant_id,
                requesterId: (string) $requesterId,
                type: 'escalation',
                resourceType: 'business_process',
                resourceId: (string) $process->id,
                reason: "Process \"{$process->name}\" failed {$process->consecutive_failures} runs in a row. "
                    .'The owner needs an answer; the answer becomes the next SOP revision.',
                context: [
                    'process_id' => (string) $process->id,
                    'process_name' => $process->name,
                    'last_execution_id' => $process->last_execution_id,
                    'errors' => $executionStatus['errors'] ?? null,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('systemization-> escalate(): approval creation failed', [
                'process_id' => (string) $process->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $process->forceFill([
            'needs_attention' => true,
            'escalation_approval_id' => $approval['id'] ?? null,
        ])->save();

        $this->eventStore->append(
            (string) $process->tenant_id,
            'systemization',
            (string) $process->id,
            'systemization.process.escalated',
            [
                'process' => $process->name,
                'approval_id' => $approval['id'] ?? null,
                'consecutive_failures' => $process->consecutive_failures,
            ],
        );
    }
}
