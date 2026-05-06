<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MemoryNodeController extends Controller
{
    /**
     * GET /api/memory/nodes — paginated memory graph nodes for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('memory_nodes')) {
            return response()->json([
                'data' => [],
                'meta' => ['note' => 'memory_nodes table not available in this environment'],
            ]);
        }

        $tenantId = (string) $request->attributes->get('tenant_id');
        $perPage = min(100, max(1, (int) $request->input('per_page', 24)));

        $q = DB::table('memory_nodes')->where('tenant_id', $tenantId);

        if ($request->filled('node_type')) {
            $q->where('node_type', (string) $request->input('node_type'));
        }

        if ($request->filled('search')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->input('search')).'%';
            $q->where('content', 'like', $term);
        }

        $paginator = $q->orderByDesc('created_at')->paginate($perPage, [
            'id', 'tenant_id', 'agent_id', 'node_type', 'content', 'metadata',
            'recency_score', 'importance_score', 'access_count', 'last_accessed_at', 'created_at', 'updated_at',
        ]);

        $items = collect($paginator->items())->map(function ($row) {
            return $this->serializeNode($row);
        })->values()->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * POST /api/memory/nodes — create a memory node (embedding optional / plane-specific).
     */
    public function store(Request $request): JsonResponse
    {
        if (! Schema::hasTable('memory_nodes')) {
            return response()->json(['message' => 'Memory store not provisioned'], 503);
        }

        $data = $request->validate([
            'node_type' => 'required|string|max:32',
            'content' => 'required|string|max:50000',
            'agent_id' => 'nullable|uuid',
            'metadata' => 'nullable|array',
        ]);

        $tenantId = (string) $request->attributes->get('tenant_id');
        $id = (string) Str::uuid();

        $insert = [
            'id' => $id,
            'tenant_id' => $tenantId,
            'agent_id' => $data['agent_id'] ?? null,
            'node_type' => $data['node_type'],
            'content' => $data['content'],
            'metadata' => json_encode($data['metadata'] ?? []),
            'recency_score' => 0,
            'importance_score' => 0,
            'access_count' => 0,
            'last_accessed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('memory_nodes', 'embedding')) {
            $insert['embedding'] = null;
        }

        DB::table('memory_nodes')->insert($insert);

        $row = DB::table('memory_nodes')->where('id', $id)->first();

        return response()->json(['data' => $this->serializeNode($row)], 201);
    }

    /**
     * GET /api/memory/nodes/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if (! Schema::hasTable('memory_nodes')) {
            return response()->json(['message' => 'Memory store not provisioned'], 503);
        }

        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = DB::table('memory_nodes')->where('id', $id)->where('tenant_id', $tenantId)->first();

        if (! $row) {
            return response()->json(['message' => 'Node not found'], 404);
        }

        return response()->json(['data' => $this->serializeNode($row)]);
    }

    /**
     * GET /api/memory/nodes/{id}/related — nodes linked via memory_edges.
     */
    public function related(Request $request, string $id): JsonResponse
    {
        if (! Schema::hasTable('memory_nodes') || ! Schema::hasTable('memory_edges')) {
            return response()->json(['data' => []]);
        }

        $tenantId = (string) $request->attributes->get('tenant_id');
        $origin = DB::table('memory_nodes')->where('id', $id)->where('tenant_id', $tenantId)->first();
        if (! $origin) {
            return response()->json(['message' => 'Node not found'], 404);
        }

        $peerIds = DB::table('memory_edges')
            ->where('source_id', $id)
            ->orWhere('target_id', $id)
            ->get()
            ->flatMap(fn ($e) => [$e->source_id, $e->target_id])
            ->unique()
            ->filter(fn ($nid) => $nid !== $id)
            ->values();

        if ($peerIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $rows = DB::table('memory_nodes')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $peerIds)
            ->get([
                'id', 'tenant_id', 'agent_id', 'node_type', 'content', 'metadata',
                'recency_score', 'importance_score', 'access_count', 'last_accessed_at', 'created_at', 'updated_at',
            ]);

        return response()->json([
            'data' => $rows->map(fn ($row) => $this->serializeNode($row))->values()->all(),
        ]);
    }

    /**
     * @param  object  $row
     * @return array<string, mixed>
     */
    private function serializeNode($row): array
    {
        $metadata = json_decode($row->metadata ?? '[]', true) ?: [];
        $preview = null;
        if (isset($metadata['embedding_preview']) && is_array($metadata['embedding_preview'])) {
            $preview = $metadata['embedding_preview'];
        }

        return [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'agent_id' => $row->agent_id,
            'node_type' => $row->node_type,
            'content' => $row->content,
            'metadata' => $metadata,
            'embedding' => $preview,
            'recency_score' => (float) ($row->recency_score ?? 0),
            'importance_score' => (float) ($row->importance_score ?? 0),
            'access_count' => (int) ($row->access_count ?? 0),
            'last_accessed_at' => $row->last_accessed_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
