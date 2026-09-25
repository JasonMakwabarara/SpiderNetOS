<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Event;
use App\Services\ApprovalEngine;
use App\Services\EventStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalController extends Controller
{
    private EventStore $eventStore;

    public function __construct(EventStore $eventStore)
    {
        $this->eventStore = $eventStore;
    }

    /**
     * Single approval with its chain steps (empty for legacy single-stage).
     */
    public function show(Request $request, $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $approval = Approval::forTenant($tenantId)->with('steps')->find($id);

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        return response()->json(['data' => $approval]);
    }

    /**
     * Delegate the current pending chain step to another tenant user.
     */
    public function delegate(Request $request, $id): JsonResponse
    {
        $request->validate([
            'to_user_id' => 'required|uuid',
            'note' => 'nullable|string|max:1000',
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $approval = DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        try {
            $result = app(ApprovalEngine::class)->delegateStep(
                $id,
                (string) $request->user()?->id,
                $request->input('to_user_id'),
                (string) $request->input('note', ''),
            );
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $result]);
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

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        // A fast path for an obvious replay. It decides nothing — see decide().
        if ($approval->status !== 'pending') {
            return response()->json([
                'error' => "Approval has already been {$approval->status}.",
            ], 409);
        }

        // Multi-stage chains resolve through the engine (per-step auth,
        // step advancement, terminal hooks, events).
        if ($approval->current_step !== null) {
            return $this->resolveChainStep($request, $id, true, (string) $request->input('reason', ''));
        }

        $event = $this->decide($request, $tenantId, $approval, granted: true);
        if ($event === null) {
            return $this->alreadyDecided($tenantId, $id);
        }

        // Resource-type hooks: some resources activate a downstream workflow
        // when their approval is granted, rather than resuming a paused DAG.
        // Driven by config/approvals.php through ApprovalEngine so the
        // single-stage and chained paths fire exactly the same hooks. Fired
        // after the decision commits, and only by the request that made it.
        app(ApprovalEngine::class)->fireResourceHook(
            (string) $approval->resource_type,
            $tenantId,
            (string) $approval->resource_id,
            true,
            (string) $request->input('reason', ''),
        );

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

        if (! $approval) {
            return response()->json(['error' => 'Approval not found.'], 404);
        }

        // A fast path for an obvious replay. It decides nothing — see decide().
        if ($approval->status !== 'pending') {
            return response()->json([
                'error' => "Approval has already been {$approval->status}.",
            ], 409);
        }

        // Multi-stage chains resolve through the engine (see approve()).
        if ($approval->current_step !== null) {
            return $this->resolveChainStep($request, $id, false, (string) $request->input('reason', ''));
        }

        $event = $this->decide($request, $tenantId, $approval, granted: false);
        if ($event === null) {
            return $this->alreadyDecided($tenantId, $id);
        }

        // Same registry-driven hooks as approve() (config/approvals.php).
        app(ApprovalEngine::class)->fireResourceHook(
            (string) $approval->resource_type,
            $tenantId,
            (string) $approval->resource_id,
            false,
            (string) $request->input('reason', ''),
        );

        return response()->json([
            'id' => $id,
            'event_id' => $event->id,
            'status' => 'rejected',
            'message' => 'Approval rejected.',
        ]);
    }

    /**
     * The single-stage decision, made exactly once.
     *
     * The status check above is a fast path and decides nothing: two
     * requests can both read `pending` before either writes. Until this, both
     * then appended an event, both wrote the projection and both fired the
     * resource hook — for an `agent_artifact` bundle, a double apply and two
     * clean-draft increments from one review. So the expected state is part
     * of the write: the update carries `status = pending` (and no chain) in
     * its WHERE clause, and only the request whose update changed a row goes
     * on. On Postgres a competing update waits on the row lock and then
     * re-evaluates that condition against the committed row, so the loser
     * sees zero rows rather than a stale `pending`.
     *
     * The decision, its event and the DAG node transition commit together or
     * not at all. The resource hook is deliberately outside: it can send mail
     * and dispatch work, and a transaction held across that would also hold
     * the global event-sequence lock for as long as the hook takes.
     *
     * Returns null when another request already decided it.
     */
    private function decide(Request $request, string $tenantId, object $approval, bool $granted): ?Event
    {
        $actor = $request->user()?->id;
        $reason = $request->input('reason');

        return DB::transaction(function () use ($tenantId, $approval, $granted, $actor, $reason): ?Event {
            // Columns must match the actual `approvals` schema
            // (2024_01_01_000008_create_approvals_table.php): approver_id /
            // responded_at — NOT resolved_by / resolved_at, which do not
            // exist and previously made this UPDATE fail on every call.
            $changed = DB::table('approvals')
                ->where('id', $approval->id)
                ->where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->whereNull('current_step')
                ->update([
                    'status' => $granted ? 'approved' : 'rejected',
                    'approver_id' => $actor,
                    'response' => $reason,
                    'responded_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($changed !== 1) {
                return null;
            }

            // Hard Rule #1: All writes go through EventStore
            $event = $this->eventStore->append(
                tenantId: $tenantId,
                aggregateType: 'approval',
                aggregateId: $approval->id,
                eventType: $granted ? 'approval.granted' : 'approval.rejected',
                payload: [
                    ($granted ? 'approved_by' : 'rejected_by') => $actor,
                    'reason' => $reason,
                    'flow_execution_id' => $approval->flow_execution_id ?? null,
                    'dag_node_id' => $approval->dag_node_id ?? null,
                ],
                metadata: [
                    'user_id' => $actor,
                ]
            );

            // Resume or fail the blocked DAG node, if there is one.
            if (! empty($approval->flow_execution_id) && ! empty($approval->dag_node_id)) {
                $granted
                    ? $this->resumeDagNode($tenantId, $approval->flow_execution_id, $approval->dag_node_id, $event->id)
                    : $this->failDagNode($tenantId, $approval->flow_execution_id, $approval->dag_node_id, (string) $reason, $event->id);
            }

            return $event;
        });
    }

    /** The loser of a race, or a replay: the answer that was already given. */
    private function alreadyDecided(string $tenantId, string $id): JsonResponse
    {
        $status = DB::table('approvals')->where('id', $id)->where('tenant_id', $tenantId)->value('status');

        return response()->json(['error' => "Approval has already been {$status}."], 409);
    }

    /**
     * Route a chained approval through ApprovalEngine::resolveStep with
     * HTTP error mapping.
     */
    private function resolveChainStep(Request $request, string $id, bool $approved, string $response): JsonResponse
    {
        try {
            $result = app(ApprovalEngine::class)->resolveStep(
                $id,
                (string) $request->user()?->id,
                $approved,
                $response,
            );
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        } catch (\LogicException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json([
            'id' => $id,
            'status' => $result['status'],
            'current_step' => $result['current_step'],
            'message' => $approved ? 'Step approved.' : 'Approval rejected.',
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
