<?php

namespace App\Http\Controllers;

use App\Services\EventStore;
use App\Models\Approval;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApprovalController extends Controller
{
    private EventStore $eventStore;

    public function __construct(EventStore $eventStore)
    {
        $this->eventStore = $eventStore;
    }

    /**
     * List approvals scoped to tenant, with optional status filter.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $query = DB::table('approvals')
            ->where('tenant_id', $tenantId);

        // Optional status filter
        if ($request->has('status')) {
            $request->validate([
                'status' => 'string|in:pending,approved,rejected,expired',
            ]);
            $query->where('status', $request->input('status'));
        }

        $approvals = $query
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($approvals);
    }

    /**
     * Approve a pending approval via EventStore (Hard Rule #1).
     * Resumes blocked DAG node if applicable.
     */
    public function approve(Request $request, $id): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $approval = DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json([
                'error' => "Approval has already been {$approval->status}.",
            ], 409);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'approval',
            aggregateId: $id,
            eventType: 'approval.granted',
            payload: [
                'approved_by' => $request->user()?->id,
                'reason' => $request->input('reason'),
                'flow_execution_id' => $approval->flow_execution_id ?? null,
                'dag_node_id' => $approval->dag_node_id ?? null,
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Update approval projection. Columns must match the actual
        // `approvals` schema (2024_01_01_000008_create_approvals_table.php):
        // approver_id / responded_at — NOT resolved_by / resolved_at, which
        // do not exist and previously made this UPDATE fail on every call.
        DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => 'approved',
                'approver_id' => $request->user()?->id,
                'response' => $request->input('reason'),
                'responded_at' => now(),
                'updated_at' => now(),
            ]);

        // Resume blocked DAG node if applicable
        if (!empty($approval->flow_execution_id) && !empty($approval->dag_node_id)) {
            $this->resumeDagNode(
                $tenantId,
                $approval->flow_execution_id,
                $approval->dag_node_id,
                $event->id,
            );
        }

        // Resource-type hooks: some resources activate a downstream workflow
        // when their approval is granted, rather than resuming a paused DAG.
        if ($approval->resource_type === 'sales_script') {
            app(\App\Services\Sales\FunnelSetupService::class)->activateFromApproval($tenantId, $approval->resource_id);
        }

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'status' => 'approved',
            'message' => 'Approval granted.',
        ]);
    }

    /**
     * Reject a pending approval via EventStore (Hard Rule #1).
     * Fails blocked DAG node if applicable.
     */
    public function reject(Request $request, $id): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $approval = DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        if ($approval->status !== 'pending') {
            return response()->json([
                'error' => "Approval has already been {$approval->status}.",
            ], 409);
        }

        // Hard Rule #1: All writes go through EventStore
        $event = $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'approval',
            aggregateId: $id,
            eventType: 'approval.rejected',
            payload: [
                'rejected_by' => $request->user()?->id,
                'reason' => $request->input('reason'),
                'flow_execution_id' => $approval->flow_execution_id ?? null,
                'dag_node_id' => $approval->dag_node_id ?? null,
            ],
            metadata: [
                'user_id' => $request->user()?->id,
            ]
        );

        // Update approval projection (see approve() for the column-name note).
        DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->update([
                'status' => 'rejected',
                'approver_id' => $request->user()?->id,
                'response' => $request->input('reason'),
                'responded_at' => now(),
                'updated_at' => now(),
            ]);

        // Fail blocked DAG node if applicable
        if (!empty($approval->flow_execution_id) && !empty($approval->dag_node_id)) {
            $this->failDagNode(
                $tenantId,
                $approval->flow_execution_id,
                $approval->dag_node_id,
                $request->input('reason'),
                $event->id,
            );
        }

        if ($approval->resource_type === 'sales_script') {
            app(\App\Services\Sales\FunnelSetupService::class)->rejectFromApproval($tenantId, $approval->resource_id, $request->input('reason'));
        }

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'status' => 'rejected',
            'message' => 'Approval rejected.',
        ]);
    }

    /**
     * Resume a blocked DAG node after approval is granted.
     */
    private function resumeDagNode(
        string $tenantId,
        string $flowExecutionId,
        string $dagNodeId,
        string $approvalEventId,
    ): void {
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow_execution',
            aggregateId: $flowExecutionId,
            eventType: 'dag.node.resumed',
            payload: [
                'dag_node_id' => $dagNodeId,
                'approval_event_id' => $approvalEventId,
                'resumed_at' => now()->toIso8601String(),
            ],
        );

        // Update the DAG node status in flow_executions if tracked
        DB::table('flow_executions')
            ->where('id', $flowExecutionId)
            ->where('tenant_id', $tenantId)
            ->update(['updated_at' => now()]);
    }

    /**
     * Fail a blocked DAG node after approval is rejected.
     */
    private function failDagNode(
        string $tenantId,
        string $flowExecutionId,
        string $dagNodeId,
        string $reason,
        string $approvalEventId,
    ): void {
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'flow_execution',
            aggregateId: $flowExecutionId,
            eventType: 'dag.node.failed',
            payload: [
                'dag_node_id' => $dagNodeId,
                'approval_event_id' => $approvalEventId,
                'reason' => $reason,
                'failed_at' => now()->toIso8601String(),
            ],
        );

        // Update the DAG node status in flow_executions if tracked
        DB::table('flow_executions')
            ->where('id', $flowExecutionId)
            ->where('tenant_id', $tenantId)
            ->update(['updated_at' => now()]);
    }
}
