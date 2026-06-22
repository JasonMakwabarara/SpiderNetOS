<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Time-limited, token-gated read-only views for traces and approvals (Share-a-Trace pattern).
 */
class ShareLinkController extends Controller
{
    private const TTL_DAYS = 7;

    public function mintTrace(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $exists = DB::table('flow_executions')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Trace not found.'], 404);
        }

        return response()->json($this->mint($tenantId, 'trace', $id));
    }

    public function mintApproval(Request $request, string $id): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $exists = DB::table('approvals')
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            return response()->json(['message' => 'Approval not found.'], 404);
        }

        return response()->json($this->mint($tenantId, 'approval', $id));
    }

    /**
     * @return array{token: string, expires_at: string, path: string}
     */
    private function mint(string $tenantId, string $resourceType, string $resourceId): array
    {
        $plain = Str::random(48);
        $hash = hash('sha256', $plain);
        $expires = now()->addDays(self::TTL_DAYS);

        DB::table('share_links')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'token_hash' => $hash,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $path = $resourceType === 'approval'
            ? "/share/approval/{$plain}"
            : "/share/trace/{$plain}";

        return [
            'token' => $plain,
            'expires_at' => $expires->toIso8601String(),
            'path' => $path,
            'share_path' => $path,
        ];
    }

    public function publicTrace(string $token): JsonResponse
    {
        $row = $this->resolveToken($token, 'trace');
        if ($row instanceof JsonResponse) {
            return $row;
        }

        $execution = DB::table('flow_executions')
            ->where('id', $row->resource_id)
            ->where('tenant_id', $row->tenant_id)
            ->first();

        if (! $execution) {
            return response()->json(['message' => 'Trace not found.'], 404);
        }

        $execution->context = json_decode($execution->context ?? 'null', true);
        $execution->errors = json_decode($execution->errors ?? 'null', true);
        $execution->results = json_decode($execution->results ?? 'null', true);

        $nodes = DB::table('dag_nodes')
            ->where('flow_id', $execution->flow_id)
            ->get();

        $edges = DB::table('dag_edges')
            ->where('flow_id', $execution->flow_id)
            ->get();

        $eventCount = 0;
        $nodeStates = [];

        if (Schema::hasTable('event_log')) {
            $events = DB::table('event_log')
                ->where('tenant_id', $row->tenant_id)
                ->where('aggregate_type', 'flow_execution')
                ->where('aggregate_id', $row->resource_id)
                ->orderBy('sequence_num')
                ->get();

            $eventCount = $events->count();

            foreach ($events as $event) {
                $payload = json_decode($event->payload, true) ?? [];
                if (isset($payload['node_id'])) {
                    $nodeStates[$payload['node_id']] = [
                        'status' => $payload['status'] ?? 'unknown',
                        'output' => $payload['output'] ?? null,
                        'error' => $payload['error'] ?? null,
                        'occurred_at' => $event->occurred_at,
                    ];
                }
            }
        }

        $durationMs = null;
        if ($execution->started_at && $execution->completed_at) {
            $durationMs = \Carbon\Carbon::parse($execution->started_at)
                ->diffInMilliseconds(\Carbon\Carbon::parse($execution->completed_at));
        }

        return response()->json([
            'kind' => 'trace',
            'expires_at' => $row->expires_at,
            'data' => [
                'id' => (string) $execution->id,
                'kind' => 'flow.execution',
                'status' => $this->normalizeStatus((string) $execution->status),
                'subject' => (string) $execution->flow_id,
                'actor' => 'system',
                'created_at' => $execution->started_at,
                'shared_at' => $row->created_at,
                'expires_at' => $row->expires_at,
                'duration_ms' => $durationMs,
                'cost_usd' => 0.0,
                'metadata' => [
                    'nodes' => $nodes,
                    'edges' => $edges,
                    'node_states' => $nodeStates,
                    'event_count' => $eventCount,
                    'errors' => $execution->errors,
                    'results' => $execution->results,
                ],
                'events' => [],
            ],
            'trace' => [
                'execution' => $execution,
                'nodes' => $nodes,
                'edges' => $edges,
                'node_states' => $nodeStates,
                'event_count' => $eventCount,
            ],
        ]);
    }

    public function publicApproval(string $token): JsonResponse
    {
        $row = $this->resolveToken($token, 'approval');
        if ($row instanceof JsonResponse) {
            return $row;
        }

        $approval = DB::table('approvals')
            ->where('id', $row->resource_id)
            ->where('tenant_id', $row->tenant_id)
            ->first();

        if (! $approval) {
            return response()->json(['message' => 'Approval not found.'], 404);
        }

        $approval->context = json_decode($approval->context ?? 'null', true);

        return response()->json([
            'kind' => 'approval',
            'expires_at' => $row->expires_at,
            'approval' => [
                'id' => $approval->id,
                'status' => $approval->status,
                'approval_type' => $approval->approval_type,
                'resource_type' => $approval->resource_type,
                'resource_id' => $approval->resource_id,
                'reason' => $approval->reason,
                'context' => $approval->context,
                'requested_at' => $approval->requested_at,
                'responded_at' => $approval->responded_at,
                'expires_at' => $approval->expires_at,
            ],
        ]);
    }

    /**
     * @return \stdClass|JsonResponse
     */
    private function resolveToken(string $token, string $expectedType)
    {
        if (strlen($token) < 32) {
            return response()->json(['message' => 'Invalid link.'], 404);
        }

        $hash = hash('sha256', $token);

        $row = DB::table('share_links')
            ->where('token_hash', $hash)
            ->where('resource_type', $expectedType)
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Link expired or revoked.'], 404);
        }

        return $row;
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'completed', 'success', 'succeeded', 'ok' => 'ok',
            'failed', 'error' => 'error',
            'running', 'pending', 'queued', 'warn' => 'warn',
            default => 'ok',
        };
    }
}
