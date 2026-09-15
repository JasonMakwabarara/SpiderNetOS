<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReplayDivergenceService
{
    /** Events DagExecutionService writes that move execution or node state. */
    private const REPLAYED_EVENT_TYPES = [
        'flow.execution_started',
        'flow.node_started',
        'flow.node_completed',
        'flow.execution_failed',
        'flow.execution_completed',
        'approval.required',
    ];

    public function buildExecutionFingerprint(string $tenantId, string $flowId, array $dag, array $context): string
    {
        return hash('sha256', json_encode([
            'tenant_id' => $tenantId,
            'flow_id' => $flowId,
            'dag' => $this->normalize($dag),
            'context' => $this->normalize($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function findCachedResult(string $tenantId, string $fingerprint): ?array
    {
        $row = DB::table('execution_fingerprint_cache')
            ->where('tenant_id', $tenantId)
            ->where('fingerprint', $fingerprint)
            ->where(function ($q) {
                $q->whereNull('valid_until')->orWhere('valid_until', '>', now());
            })
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'cache_id' => $row->id,
            'source_execution_id' => $row->source_execution_id,
            'result_payload' => json_decode($row->result_payload, true),
            'metadata' => json_decode($row->metadata ?? '{}', true),
            'valid_until' => $row->valid_until,
        ];
    }

    public function cacheExecutionResult(
        string $tenantId,
        string $executionId,
        string $fingerprint,
        array $resultPayload,
        array $metadata = [],
        ?int $ttlMinutes = null
    ): void {
        $ttlMinutes = $ttlMinutes ?? (int) config('services.spidernet.fingerprint_cache_ttl_minutes', 120);
        DB::table('execution_fingerprint_cache')->updateOrInsert(
            ['tenant_id' => $tenantId, 'fingerprint' => $fingerprint],
            [
                'id' => (string) Str::uuid(),
                'source_execution_id' => $executionId,
                'result_payload' => json_encode($resultPayload, JSON_THROW_ON_ERROR),
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'valid_until' => $ttlMinutes ? now()->addMinutes($ttlMinutes) : null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function detectDivergence(string $tenantId, string $executionId): array
    {
        $liveExecution = DB::table('flow_executions')
            ->where('tenant_id', $tenantId)
            ->where('id', $executionId)
            ->first();

        if (!$liveExecution) {
            throw new \RuntimeException('Execution not found for divergence check.');
        }

        // Runtime node state lives in execution_dag_nodes (dag_nodes is the
        // per-flow DESIGN table keyed by flow_id — it has no execution_id, and
        // on Postgres the bad column poisons the surrounding transaction).
        $liveNodes = DB::table('execution_dag_nodes')
            ->where('execution_id', $executionId)
            ->orderBy('node_id')
            ->get()
            ->keyBy('node_id')
            ->map(function ($n) {
                return [
                    'status' => $n->status,
                    'retry_count' => $n->retry_count,
                    'last_error' => $n->last_error,
                    'result' => $n->result ? json_decode($n->result, true) : null,
                ];
            })
            ->toArray();

        // DagExecutionService appends flow.* events in EventStore's short form,
        // so their aggregate is "flow" with a fresh random aggregate_id: the
        // execution is only named in payload.execution_id (approval.required
        // carries it there too). Matching on aggregate_id found no events at
        // all, and every checked execution read as diverged.
        // Portable JSON path (compiles to ->> on pgsql, json_extract on
        // sqlite); raw JSON_EXTRACT is MySQL-only and throws on Postgres jsonb.
        $events = DB::table('event_log')
            ->where('tenant_id', $tenantId)
            ->whereIn('event_type', self::REPLAYED_EVENT_TYPES)
            ->where('payload->execution_id', (string) $executionId)
            ->orderBy('sequence_num')
            ->get();

        $replay = [
            'execution_status' => 'pending',
            'nodes' => [],
        ];

        foreach ($events as $event) {
            $payload = json_decode($event->payload, true) ?? [];
            switch ($event->event_type) {
                case 'flow.execution_started':
                    $replay['execution_status'] = 'running';
                    break;
                case 'flow.node_started':
                    if (!empty($payload['node_id'])) {
                        $replay['nodes'][$payload['node_id']] = array_merge(
                            $replay['nodes'][$payload['node_id']] ?? [],
                            ['status' => 'running']
                        );
                    }
                    break;
                case 'flow.node_completed':
                    if (!empty($payload['node_id'])) {
                        $replay['nodes'][$payload['node_id']] = array_merge(
                            $replay['nodes'][$payload['node_id']] ?? [],
                            ['status' => 'completed', 'result' => $payload['result'] ?? null]
                        );
                    }
                    break;
                case 'flow.execution_failed':
                    $replay['execution_status'] = 'failed';
                    // Exhausted retries fail the node and the execution in one
                    // step, and the node is only named here as failed_node.
                    if (!empty($payload['failed_node'])) {
                        $replay['nodes'][$payload['failed_node']] = array_merge(
                            $replay['nodes'][$payload['failed_node']] ?? [],
                            ['status' => 'failed']
                        );
                    }
                    break;
                case 'flow.execution_completed':
                    $replay['execution_status'] = 'completed';
                    break;
                case 'approval.required':
                    $replay['execution_status'] = 'paused';
                    if (!empty($payload['node_id'])) {
                        $replay['nodes'][$payload['node_id']] = array_merge(
                            $replay['nodes'][$payload['node_id']] ?? [],
                            ['status' => 'waiting_approval']
                        );
                    }
                    break;
            }
        }

        $divergences = [];

        if (($liveExecution->status ?? null) !== $replay['execution_status']) {
            $divergences[] = [
                'type' => 'execution_status_mismatch',
                'live' => $liveExecution->status,
                'replay' => $replay['execution_status'],
            ];
        }

        foreach ($liveNodes as $nodeId => $liveNodeState) {
            $replayedNode = $replay['nodes'][$nodeId] ?? null;
            if (!$replayedNode) {
                // A node that never started has no events, which only
                // diverges when the live row says it got past pending.
                if (($liveNodeState['status'] ?? null) !== 'pending') {
                    $divergences[] = [
                        'type' => 'node_missing_in_replay',
                        'node_id' => $nodeId,
                        'live' => $liveNodeState['status'] ?? null,
                    ];
                }
                continue;
            }

            if (($liveNodeState['status'] ?? null) !== $replayedNode['status']) {
                $divergences[] = [
                    'type' => 'node_status_mismatch',
                    'node_id' => $nodeId,
                    'live' => $liveNodeState['status'] ?? null,
                    'replay' => $replayedNode['status'],
                ];
            }
        }

        $status = empty($divergences) ? 'clean' : 'diverged';

        $report = [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'execution_id' => $executionId,
            'status' => $status,
            'replay_state' => $replay,
            'live_state' => [
                'execution_status' => $liveExecution->status,
                'nodes' => $liveNodes,
            ],
            'divergences' => $divergences,
            'divergence_count' => count($divergences),
            'created_at' => now(),
            'unchanged' => false,
        ];

        // The sweep re-checks the same executions every ten minutes. An
        // outcome identical to the last report is not news, so hand that
        // report back instead of storing (and alerting on) it again.
        $previous = DB::table('replay_divergence_reports')
            ->where('tenant_id', $tenantId)
            ->where('execution_id', $executionId)
            ->orderByDesc('created_at')
            ->first();

        if ($previous
            && $previous->status === $status
            && $this->sameDivergences(json_decode($previous->divergences, true) ?? [], $divergences)) {
            return array_merge($report, [
                'id' => $previous->id,
                'created_at' => $previous->created_at,
                'unchanged' => true,
            ]);
        }

        DB::table('replay_divergence_reports')->insert([
            'id' => $report['id'],
            'tenant_id' => $tenantId,
            'execution_id' => $executionId,
            'status' => $report['status'],
            'replay_state' => json_encode($report['replay_state'], JSON_THROW_ON_ERROR),
            'live_state' => json_encode($report['live_state'], JSON_THROW_ON_ERROR),
            'divergences' => json_encode($report['divergences'], JSON_THROW_ON_ERROR),
            'divergence_count' => $report['divergence_count'],
            'created_at' => $report['created_at'],
        ]);

        return $report;
    }

    public function invalidateByFlow(string $tenantId, string $flowId): int
    {
        return DB::table('execution_fingerprint_cache')
            ->where('tenant_id', $tenantId)
            ->whereRaw("JSON_EXTRACT(metadata, '$.flow_id') = ?", [$flowId])
            ->delete();
    }

    public function invalidateByCriticalAgentConfig(string $tenantId, string $agentId): int
    {
        return DB::table('execution_fingerprint_cache')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($agentId) {
                $q->whereRaw("JSON_EXTRACT(metadata, '$.agent_id') = ?", [$agentId])
                  ->orWhereRaw("JSON_SEARCH(JSON_EXTRACT(metadata, '$.related_agents'), 'one', ?) IS NOT NULL", [$agentId]);
            })
            ->delete();
    }

    /**
     * @param  array<int, mixed>  $stored
     * @param  array<int, mixed>  $current
     */
    private function sameDivergences(array $stored, array $current): bool
    {
        return json_encode($this->normalize($stored)) === json_encode($this->normalize($current));
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);
            if ($isAssoc) {
                ksort($value);
            }
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalize($v);
            }
        }

        return $value;
    }
}
