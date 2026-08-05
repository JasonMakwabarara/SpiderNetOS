<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\EventStore;
use App\Services\DagExecutionService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ApprovalEngine — SpiderNet OS v3.2
 *
 * Human-in-the-loop approval gates. Any DAG node (or external action) can
 * be paused until a designated approver grants or rejects the request.
 * Approval policies are configurable per tenant / resource-type / action.
 */
class ApprovalEngine
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly DagExecutionService $dagExecutionService,
    ) {}

    // ------------------------------------------------------------------ //
    //  Create / Resolve
    // ------------------------------------------------------------------ //

    /**
     * Create a new approval request.
     *
     * @param  string       $tenantId      Tenant scope.
     * @param  string       $requesterId   User or agent that initiated the request.
     * @param  string       $type          Approval type (e.g. "manual", "budget", "security").
     * @param  string       $resourceType  The kind of resource under review (e.g. "flow_execution", "deployment").
     * @param  string       $resourceId    Identifier of the specific resource instance.
     * @param  string       $reason        Human-readable justification for the request.
     * @param  array        $context       Arbitrary metadata attached to the approval.
     * @return array        The created approval record.
     */
    public function createApproval(
        string $tenantId,
        string $requesterId,
        string $type,
        string $resourceType,
        string $resourceId,
        string $reason,
        array  $context = [],
    ): array {
        $approvalId = (string) Str::uuid();

        $record = [
            'id'            => $approvalId,
            'tenant_id'     => $tenantId,
            'requester_id'  => $requesterId,
            'approval_type' => $type,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'reason'        => $reason,
            'context'       => json_encode($context, JSON_THROW_ON_ERROR),
            'status'        => 'pending',
            'requested_at'  => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];

        DB::table('approvals')->insert($record);

        $this->eventStore->append($tenantId, 'approval.requested', [
            'approval_id'   => $approvalId,
            'requester_id'  => $requesterId,
            'approval_type' => $type,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'reason'        => $reason,
        ]);

        Log::info('Approval requested', compact('approvalId', 'tenantId', 'resourceType', 'resourceId'));

        return $this->formatApproval(
            DB::table('approvals')->where('id', $approvalId)->first()
        );
    }

    /**
     * Resolve an existing approval (grant or reject).
     *
     * @param  string  $approvalId  The approval to resolve.
     * @param  string  $approverId  The user performing the approval action.
     * @param  bool    $approved    True = granted, false = rejected.
     * @param  string  $response    Optional message from the approver.
     * @return array   The updated approval record.
     */
    public function resolveApproval(
        string $approvalId,
        string $approverId,
        bool   $approved,
        string $response = '',
    ): array {
        $approval = DB::table('approvals')->where('id', $approvalId)->first();

        if (!$approval) {
            throw new \InvalidArgumentException("Approval [{$approvalId}] not found.");
        }

        if ($approval->status !== 'pending') {
            throw new \LogicException("Approval [{$approvalId}] has already been resolved (status: {$approval->status}).");
        }

        $newStatus = $approved ? 'granted' : 'rejected';

        DB::table('approvals')
            ->where('id', $approvalId)
            ->update([
                'status'      => $newStatus,
                'approver_id' => $approverId,
                'response'    => $response,
                'responded_at' => now(),
                'updated_at'  => now(),
            ]);

        $eventType = $approved ? 'approval.granted' : 'approval.rejected';

        $this->eventStore->append($approval->tenant_id, $eventType, [
            'approval_id'  => $approvalId,
            'approver_id'  => $approverId,
            'response'     => $response,
        ]);

        Log::info("Approval {$newStatus}", compact('approvalId', 'approverId'));

        // If the approval was granted, attempt to resume any blocked execution.
        if ($approved) {
            $this->resumeBlockedExecution($approvalId);
        }

        return $this->formatApproval(
            DB::table('approvals')->where('id', $approvalId)->first()
        );
    }

    // ------------------------------------------------------------------ //
    //  Queries
    // ------------------------------------------------------------------ //

    /**
     * Return all pending approvals for a tenant.
     *
     * @param  string  $tenantId
     * @return array
     */
    public function getPendingApprovals(string $tenantId): array
    {
        return DB::table('approvals')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($row) => $this->formatApproval($row))
            ->toArray();
    }

    /**
     * Check whether an approval gate is configured for the given tenant,
     * resource type, and action combination.
     *
     * Approval policies are stored in the `approval_policies` table:
     *   tenant_id | resource_type | action | enabled
     *
     * @param  string  $tenantId
     * @param  string  $resourceType
     * @param  string  $action
     * @return bool
     */
    public function isApprovalRequired(string $tenantId, string $resourceType, string $action): bool
    {
        $policy = DB::table('approval_policies')
            ->where('tenant_id', $tenantId)
            ->where('resource_type', $resourceType)
            ->where('action', $action)
            ->first();

        return $policy !== null && (bool) $policy->enabled;
    }

    // ------------------------------------------------------------------ //
    //  Execution resumption
    // ------------------------------------------------------------------ //

    /**
     * Resume a DAG execution that was waiting on the given approval.
     *
     * The approval context must contain `execution_id` and `node_id` to
     * identify which execution/node to resume.
     *
     * @param  string  $approvalId
     * @return void
     */
    public function resumeBlockedExecution(string $approvalId): void
    {
        $approval = DB::table('approvals')->where('id', $approvalId)->first();

        if (!$approval) {
            return;
        }

        $context = json_decode($approval->context, true, 512, JSON_THROW_ON_ERROR);

        $executionId = $context['execution_id'] ?? null;
        $nodeId      = $context['node_id'] ?? null;

        if (!$executionId || !$nodeId) {
            Log::debug('Approval has no linked execution; skipping resume.', ['approvalId' => $approvalId]);
            return;
        }

        // Transition the node from waiting_approval back to pending so the
        // DAG scheduler can pick it up.
        DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->where('node_id', $nodeId)
            ->where('status', 'waiting_approval')
            ->update([
                'status'     => 'pending',
                'updated_at' => now(),
            ]);

        Log::info('Resuming blocked execution after approval', compact('approvalId', 'executionId', 'nodeId'));

        // Verify immutable pre-approval snapshot before resume.
        $checkpoint = DB::table('approval_checkpoints')
            ->where('execution_id', $executionId)
            ->where('step', $nodeId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first();

        if ($checkpoint) {
            $checkpointPayload = json_decode($checkpoint->payload, true);
            $currentExecution = DB::table('flow_executions')->where('id', $executionId)->first();
            $currentContext = $currentExecution ? (json_decode($currentExecution->context, true) ?? []) : [];
            $currentHash = hash('sha256', json_encode([
                'execution_id' => $executionId,
                'node_id' => $nodeId,
                'context' => $currentContext,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $storedHash = $checkpointPayload['immutable_state_hash']
                ?? $checkpointPayload['snapshot_hash']
                ?? null;

            if ($storedHash && $storedHash !== $currentHash) {
                throw new \RuntimeException('State tampering detected on approval resume.');
            }

            DB::table('approval_checkpoints')
                ->where('id', $checkpoint->id)
                ->update([
                    'status' => 'approved',
                    'updated_at' => now(),
                ]);
        }

        // Re-dispatch the node.
        $this->dagExecutionService->executeNode($executionId, $nodeId);
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    /**
     * Normalise a raw DB row into a plain array.
     */
    private function formatApproval(object $row): array
    {
        return [
            'id'            => $row->id,
            'tenant_id'     => $row->tenant_id,
            'requester_id'  => $row->requester_id,
            'approver_id'   => $row->approver_id ?? null,
            'type'          => $row->approval_type,
            'resource_type' => $row->resource_type,
            'resource_id'   => $row->resource_id,
            'reason'        => $row->reason,
            'context'       => json_decode($row->context, true),
            'status'        => $row->status,
            'response'      => $row->response ?? null,
            'resolved_at'   => $row->responded_at ?? null,
            'created_at'    => $row->created_at,
            'updated_at'    => $row->updated_at,
        ];
    }
}
