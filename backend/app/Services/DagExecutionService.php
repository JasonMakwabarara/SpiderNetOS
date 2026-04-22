<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\EventStore;
use App\Services\MetaPlanner;
use App\Services\ReplayDivergenceService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DagExecutionService — SpiderNet OS v3.2
 *
 * Manages the full lifecycle of DAG-based flow executions:
 * creating executions, dispatching nodes to agents via MetaPlanner,
 * tracking completion/failure, and enforcing dependency ordering.
 *
 * All state mutations are persisted through EventStore.
 */
class DagExecutionService
{
    /** Maximum retry attempts for a failed node before escalating. */
    private const MAX_NODE_RETRIES = 3;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly MetaPlanner $metaPlanner,
        private readonly ReplayDivergenceService $replayDivergence,
    ) {}

    // ------------------------------------------------------------------ //
    //  Execution lifecycle
    // ------------------------------------------------------------------ //

    /**
     * Create a new flow execution, parse the flow DAG, and persist all
     * DagNode entries with their dependency edges.
     *
     * @param  string  $tenantId   Tenant scope.
     * @param  string  $flowId     Identifier of the flow template.
     * @param  array   $context    Arbitrary key-value context passed to every node.
     * @return array   The created execution record.
     */
    public function createExecution(string $tenantId, string $flowId, array $context = []): array
    {
        $executionId = (string) Str::uuid();

        // Fetch the flow definition (JSON DAG).
        $flow = DB::table('flows')
            ->where('tenant_id', $tenantId)
            ->where('id', $flowId)
            ->first();

        if (!$flow) {
            throw new \InvalidArgumentException("Flow [{$flowId}] not found for tenant [{$tenantId}].");
        }

        $dag = json_decode($flow->dag, true, 512, JSON_THROW_ON_ERROR);

        $fingerprint = $this->replayDivergence->buildExecutionFingerprint(
            tenantId: $tenantId,
            flowId: $flowId,
            dag: $dag,
            context: $context,
        );

        $cached = $this->replayDivergence->findCachedResult($tenantId, $fingerprint);
        if ($cached) {
            $event = $this->eventStore->append($tenantId, 'flow.execution_cache_hit', [
                'flow_id' => $flowId,
                'fingerprint' => $fingerprint,
                'source_execution_id' => $cached['source_execution_id'] ?? null,
            ]);

            return [
                'execution_id' => null,
                'tenant_id' => $tenantId,
                'flow_id' => $flowId,
                'status' => 'cached',
                'cache' => $cached,
                'event_id' => $event->id,
                'fingerprint' => $fingerprint,
            ];
        }

        // Persist execution row.
        DB::table('flow_executions')->insert([
            'id'         => $executionId,
            'tenant_id'  => $tenantId,
            'flow_id'    => $flowId,
            'status'     => 'running',
            'context'    => json_encode($context, JSON_THROW_ON_ERROR),
            'fingerprint'=> $fingerprint,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create DagNode entries and dependency mappings.
        $this->createDagNodes($executionId, $tenantId, $dag);

        // Record domain event.
        $this->eventStore->append($tenantId, 'flow.execution_started', [
            'execution_id' => $executionId,
            'flow_id'      => $flowId,
            'context'      => $context,
        ]);

        Log::info('DagExecution created', compact('executionId', 'tenantId', 'flowId'));

        // Auto-dispatch root nodes (those with zero dependencies).
        $this->dispatchReadyNodes($executionId, $tenantId);

        return $this->getExecutionStatus($executionId);
    }

    /**
     * Execute a single DAG node by dispatching it to the appropriate agent
     * via MetaPlanner.
     *
     * @param  string  $executionId
     * @param  string  $nodeId
     * @return void
     */
    public function executeNode(string $executionId, string $nodeId): void
    {
        $node = $this->getNode($executionId, $nodeId);

        if (!$node) {
            throw new \InvalidArgumentException("Node [{$nodeId}] not found in execution [{$executionId}].");
        }

        $execution = DB::table('flow_executions')->where('id', $executionId)->first();
        $context   = json_decode($execution->context, true, 512, JSON_THROW_ON_ERROR);

        // Mark node as running.
        DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->where('node_id', $nodeId)
            ->update([
                'status'     => 'running',
                'started_at' => now(),
                'updated_at' => now(),
            ]);

        $this->eventStore->append($execution->tenant_id, 'flow.node_started', [
            'execution_id' => $executionId,
            'node_id'      => $nodeId,
            'node_type'    => $node->node_type,
        ]);

        // Dispatch to the agent resolved by MetaPlanner.
        $nodeConfig = json_decode($node->config, true, 512, JSON_THROW_ON_ERROR);

        // Hard approval gate: persist checkpoint and stop execution.
        if (($nodeConfig['approval_required'] ?? false) === true) {
            $checkpointId = $this->createApprovalCheckpoint(
                executionId: $executionId,
                tenantId: $execution->tenant_id,
                nodeId: $nodeId,
                nodeConfig: $nodeConfig,
                context: $context,
            );

            DB::table('dag_nodes')
                ->where('execution_id', $executionId)
                ->where('node_id', $nodeId)
                ->update([
                    'status' => 'waiting_approval',
                    'updated_at' => now(),
                ]);

            DB::table('flow_executions')
                ->where('id', $executionId)
                ->update([
                    'status' => 'paused',
                    'updated_at' => now(),
                ]);

            $this->eventStore->append(
                tenantId: $execution->tenant_id,
                aggregateType: 'approval_checkpoint',
                aggregateId: $checkpointId,
                eventType: 'approval.required',
                payload: [
                    'execution_id' => $executionId,
                    'node_id' => $nodeId,
                    'checkpoint_id' => $checkpointId,
                    'immutable_state_hash' => $this->buildStateHash($executionId, $nodeId, $context),
                ]
            );

            return;
        }

        $dispatchResult = $this->metaPlanner->dispatch(
            tenantId: $execution->tenant_id,
            agentId: $nodeConfig['agent_id'] ?? $node->agent_id ?? 'atlas',
            intent: $nodeConfig['intent'] ?? 'execute_node',
            context: array_merge($context, [
                'execution_id' => $executionId,
                'node_id' => $nodeId,
                'node_type' => $node->node_type,
                'node_config' => $nodeConfig,
            ]),
            flowId: $execution->flow_id,
        );

        if (($dispatchResult['status'] ?? '') === 'blocked') {
            $this->failNode($executionId, $nodeId, $dispatchResult['reason'] ?? 'dispatch_blocked');
        }
    }

    /**
     * Mark a node as completed, persist its result, then check whether
     * downstream dependents are now ready to run.
     *
     * @param  string  $executionId
     * @param  string  $nodeId
     * @param  array   $result  Output produced by the agent.
     * @return void
     */
    public function completeNode(string $executionId, string $nodeId, array $result = []): void
    {
        DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->where('node_id', $nodeId)
            ->update([
                'status'       => 'completed',
                'result'       => json_encode($result, JSON_THROW_ON_ERROR),
                'completed_at' => now(),
                'updated_at'   => now(),
            ]);

        $execution = DB::table('flow_executions')->where('id', $executionId)->first();

        $this->eventStore->append($execution->tenant_id, 'flow.node_completed', [
            'execution_id' => $executionId,
            'node_id'      => $nodeId,
            'result'       => $result,
        ]);

        Log::info('DagNode completed', compact('executionId', 'nodeId'));

        // Dispatch newly-unblocked downstream nodes.
        $this->dispatchReadyNodes($executionId, $execution->tenant_id);

        // If every node is complete, mark the whole execution as completed.
        $this->checkExecutionCompletion($executionId);

        // Cache execution outcome by fingerprint for deterministic reuse.
        $execution = DB::table('flow_executions')->where('id', $executionId)->first();
        if ($execution && !empty($execution->fingerprint)) {
            $resultPayload = [
                'execution_id' => $executionId,
                'status' => $execution->status,
                'flow_id' => $execution->flow_id,
                'node_result' => $result,
            ];
            $this->replayDivergence->cacheExecutionResult(
                tenantId: $execution->tenant_id,
                executionId: $executionId,
                fingerprint: $execution->fingerprint,
                resultPayload: $resultPayload,
                metadata: [
                    'source' => 'dag_execution_complete',
                    'flow_id' => $execution->flow_id,
                    'related_agents' => [],
                ],
                ttlMinutes: 120,
            );
        }
    }

    /**
     * Mark a node as failed. Apply failure policy: retry up to MAX_NODE_RETRIES
     * times, otherwise fail the entire execution.
     *
     * @param  string  $executionId
     * @param  string  $nodeId
     * @param  string  $error  Human-readable error description.
     * @return void
     */
    public function failNode(string $executionId, string $nodeId, string $error): void
    {
        $node = $this->getNode($executionId, $nodeId);

        $retryCount = (int) ($node->retry_count ?? 0);

        if ($retryCount < self::MAX_NODE_RETRIES) {
            // Retry: reset to pending and increment retry counter.
            DB::table('dag_nodes')
                ->where('execution_id', $executionId)
                ->where('node_id', $nodeId)
                ->update([
                    'status'      => 'pending',
                    'retry_count' => $retryCount + 1,
                    'last_error'  => $error,
                    'updated_at'  => now(),
                ]);

            Log::warning('DagNode retrying', [
                'executionId' => $executionId,
                'nodeId'      => $nodeId,
                'attempt'     => $retryCount + 1,
            ]);

            $this->executeNode($executionId, $nodeId);
            return;
        }

        // Retries exhausted — mark node and execution as failed.
        DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->where('node_id', $nodeId)
            ->update([
                'status'     => 'failed',
                'last_error' => $error,
                'updated_at' => now(),
            ]);

        DB::table('flow_executions')
            ->where('id', $executionId)
            ->update([
                'status'     => 'failed',
                'updated_at' => now(),
            ]);

        $execution = DB::table('flow_executions')->where('id', $executionId)->first();

        $this->eventStore->append($execution->tenant_id, 'flow.execution_failed', [
            'execution_id' => $executionId,
            'failed_node'  => $nodeId,
            'error'        => $error,
        ]);

        // Temporal-grade replay + divergence detection on failure path.
        try {
            $report = $this->replayDivergence->detectDivergence($execution->tenant_id, $executionId);
            if (($report['status'] ?? 'clean') !== 'clean') {
                $this->eventStore->append($execution->tenant_id, 'flow.execution_diverged', [
                    'execution_id' => $executionId,
                    'divergence_report_id' => $report['id'] ?? null,
                    'divergence_count' => $report['divergence_count'] ?? 0,
                ]);
            }
        } catch (\Throwable $replayError) {
            Log::warning('Replay divergence check failed', [
                'execution_id' => $executionId,
                'error' => $replayError->getMessage(),
            ]);
        }

        Log::error('DagExecution failed', compact('executionId', 'nodeId', 'error'));
    }

    // ------------------------------------------------------------------ //
    //  Query
    // ------------------------------------------------------------------ //

    /**
     * Return the full execution state including every node's status.
     *
     * @param  string  $executionId
     * @return array
     */
    public function getExecutionStatus(string $executionId): array
    {
        $execution = DB::table('flow_executions')->where('id', $executionId)->first();

        if (!$execution) {
            throw new \InvalidArgumentException("Execution [{$executionId}] not found.");
        }

        $nodes = DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->get()
            ->map(fn ($n) => [
                'node_id'      => $n->node_id,
                'node_type'    => $n->node_type,
                'label'        => $n->label,
                'status'       => $n->status,
                'retry_count'  => $n->retry_count,
                'last_error'   => $n->last_error,
                'result'       => $n->result ? json_decode($n->result, true) : null,
                'started_at'   => $n->started_at,
                'completed_at' => $n->completed_at,
            ])
            ->toArray();

        $edges = DB::table('dag_edges')
            ->where('execution_id', $executionId)
            ->get()
            ->map(fn ($e) => [
                'from_node_id' => $e->from_node_id,
                'to_node_id'   => $e->to_node_id,
            ])
            ->toArray();

        return [
            'execution_id' => $execution->id,
            'tenant_id'    => $execution->tenant_id,
            'flow_id'      => $execution->flow_id,
            'status'       => $execution->status,
            'context'      => json_decode($execution->context, true),
            'nodes'        => $nodes,
            'edges'        => $edges,
            'created_at'   => $execution->created_at,
            'updated_at'   => $execution->updated_at,
        ];
    }

    // ------------------------------------------------------------------ //
    //  Internal helpers
    // ------------------------------------------------------------------ //

    /**
     * Parse the DAG definition and persist nodes + dependency edges.
     */
    private function createDagNodes(string $executionId, string $tenantId, array $dag): void
    {
        $nodes = $dag['nodes'] ?? [];
        $edges = $dag['edges'] ?? [];

        foreach ($nodes as $node) {
            DB::table('dag_nodes')->insert([
                'id'           => (string) Str::uuid(),
                'execution_id' => $executionId,
                'tenant_id'    => $tenantId,
                'node_id'      => $node['id'],
                'node_type'    => $node['type'],
                'label'        => $node['label'] ?? $node['id'],
                'config'       => json_encode($node['config'] ?? [], JSON_THROW_ON_ERROR),
                'status'       => 'pending',
                'retry_count'  => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        foreach ($edges as $edge) {
            DB::table('dag_edges')->insert([
                'id'           => (string) Str::uuid(),
                'execution_id' => $executionId,
                'from_node_id' => $edge['from'],
                'to_node_id'   => $edge['to'],
                'created_at'   => now(),
            ]);
        }
    }

    /**
     * Find all pending nodes whose dependencies are fully completed and
     * dispatch them for execution.
     */
    private function dispatchReadyNodes(string $executionId, string $tenantId): void
    {
        $pendingNodes = DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->where('status', 'pending')
            ->get();

        foreach ($pendingNodes as $node) {
            $unmetDeps = DB::table('dag_edges')
                ->join('dag_nodes', function ($join) use ($executionId) {
                    $join->on('dag_edges.from_node_id', '=', 'dag_nodes.node_id')
                         ->where('dag_nodes.execution_id', '=', $executionId);
                })
                ->where('dag_edges.execution_id', $executionId)
                ->where('dag_edges.to_node_id', $node->node_id)
                ->where('dag_nodes.status', '!=', 'completed')
                ->count();

            if ($unmetDeps === 0) {
                $this->executeNode($executionId, $node->node_id);
            }
        }
    }

    /**
     * If every node in the execution is completed, mark the execution itself
     * as completed and emit a domain event.
     */
    private function checkExecutionCompletion(string $executionId): void
    {
        $incomplete = DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->where('status', '!=', 'completed')
            ->count();

        if ($incomplete === 0) {
            DB::table('flow_executions')
                ->where('id', $executionId)
                ->update([
                    'status'     => 'completed',
                    'updated_at' => now(),
                ]);

            $execution = DB::table('flow_executions')->where('id', $executionId)->first();

            $this->eventStore->append($execution->tenant_id, 'flow.execution_completed', [
                'execution_id' => $executionId,
                'flow_id'      => $execution->flow_id,
            ]);

            Log::info('DagExecution completed', compact('executionId'));
        }
    }

    /**
     * Create persisted approval checkpoint with immutable pre-approval state snapshot.
     */
    private function createApprovalCheckpoint(
        string $executionId,
        string $tenantId,
        string $nodeId,
        array $nodeConfig,
        array $context
    ): string {
        $checkpointId = (string) Str::uuid();
        $snapshot = [
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'context' => $context,
            'node_config' => $nodeConfig,
            'created_at' => now()->toIso8601String(),
        ];

        $snapshot['immutable_state_hash'] = $this->buildStateHash($executionId, $nodeId, $context);

        DB::table('approval_checkpoints')->insert([
            'id' => $checkpointId,
            'execution_id' => $executionId,
            'step' => $nodeId,
            'status' => 'pending',
            'payload' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $checkpointId;
    }

    /**
     * Deterministic immutable state hash used for pre-approval auditing.
     */
    private function buildStateHash(string $executionId, string $nodeId, array $context): string
    {
        return hash('sha256', json_encode([
            'execution_id' => $executionId,
            'node_id' => $nodeId,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Fetch a single DagNode row.
     */
    private function getNode(string $executionId, string $nodeId): ?object
    {
        return DB::table('dag_nodes')
            ->where('execution_id', $executionId)
            ->where('node_id', $nodeId)
            ->first();
    }
}
