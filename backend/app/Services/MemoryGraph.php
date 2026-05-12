<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MemoryGraph
{
    private const VECTOR_DIMENSION = 1536; // OpenAI text-embedding-ada-002
    private const SIMILARITY_THRESHOLD = 0.8;

    public function __construct(
        private readonly EventStore $eventStore
    ) {}

    /**
     * Store knowledge with vector embeddings for semantic search
     */
    public function store(
        string $tenantId,
        array $content,
        array $metadata = [],
        array $relationships = []
    ): string {
        $memoryId = (string) \Illuminate\Support\Str::uuid();

        // Generate vector embedding for semantic search
        $vector = $this->generateEmbedding($content['text'] ?? json_encode($content));

        // Store in memory_nodes table
        DB::table('memory_nodes')->insert([
            'id' => $memoryId,
            'tenant_id' => $tenantId,
            'content' => json_encode($content),
            'metadata' => json_encode($metadata),
            'vector_embedding' => json_encode($vector),
            'importance_score' => $this->calculateImportance($content, $metadata),
            'access_count' => 0,
            'last_accessed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create relationships
        foreach ($relationships as $relationship) {
            $this->createRelationship(
                $memoryId,
                $relationship['target_id'],
                $relationship['type'],
                $relationship['strength'] ?? 1.0
            );
        }

        // Record memory storage event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'memory_node',
            aggregateId: $memoryId,
            eventType: 'memory.stored',
            payload: [
                'content_type' => $content['type'] ?? 'general',
                'importance_score' => $this->calculateImportance($content, $metadata),
                'relationship_count' => count($relationships),
            ],
            metadata: ['memory_system' => 'graph']
        );

        return $memoryId;
    }

    /**
     * Semantic search with vector similarity
     */
    public function retrieve(
        string $tenantId,
        string $query,
        array $filters = [],
        int $limit = 10
    ): array {
        $queryVector = $this->generateEmbedding($query);

        // Build base query with vector similarity
        $baseQuery = DB::table('memory_nodes')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->selectRaw("
                *,
                (vector_embedding <=> ?) as similarity_score,
                (importance_score + (access_count * 0.1) + GREATEST(0, (30 - DATEDIFF(NOW(), created_at)))) as relevance_score
            ", [json_encode($queryVector)])
            ->having('similarity_score', '>=', self::SIMILARITY_THRESHOLD);

        // Apply filters
        if (isset($filters['content_type'])) {
            $baseQuery->whereRaw("JSON_EXTRACT(metadata, '$.content_type') = ?", [$filters['content_type']]);
        }

        if (isset($filters['importance_min'])) {
            $baseQuery->where('importance_score', '>=', $filters['importance_min']);
        }

        if (isset($filters['time_range'])) {
            $baseQuery->where('created_at', '>=', now()->subDays($filters['time_range']));
        }

        $results = $baseQuery
            ->orderBy('relevance_score', 'desc')
            ->orderBy('similarity_score', 'desc')
            ->limit($limit)
            ->get();

        // Update access counts for retrieved memories
        $accessedIds = $results->pluck('id')->toArray();
        if (!empty($accessedIds)) {
            DB::table('memory_nodes')
                ->whereIn('id', $accessedIds)
                ->increment('access_count');

            // Record access event
            $this->eventStore->append(
                tenantId: $tenantId,
                aggregateType: 'memory_retrieval',
                aggregateId: \Illuminate\Support\Str::uuid(),
                eventType: 'memory.retrieved',
                payload: [
                    'query' => $query,
                    'results_count' => count($accessedIds),
                    'avg_similarity' => $results->avg('similarity_score'),
                ]
            );
        }

        return $results->map(function ($result) {
            return [
                'id' => $result->id,
                'content' => json_decode($result->content, true),
                'metadata' => json_decode($result->metadata, true),
                'similarity_score' => $result->similarity_score,
                'relevance_score' => $result->relevance_score,
                'importance_score' => $result->importance_score,
                'created_at' => $result->created_at,
            ];
        })->toArray();
    }

    /**
     * Create relationship between memory nodes
     */
    public function createRelationship(
        string $sourceId,
        string $targetId,
        string $relationshipType,
        float $strength = 1.0
    ): void {
        DB::table('memory_relationships')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'source_node_id' => $sourceId,
            'target_node_id' => $targetId,
            'relationship_type' => $relationshipType,
            'strength' => $strength,
            'created_at' => now(),
        ]);
    }

    /**
     * Find patterns across memory nodes
     */
    public function findPatterns(string $tenantId, array $criteria): array
    {
        // Find frequently co-occurring concepts
        $patterns = DB::select("
            SELECT
                JSON_EXTRACT(m1.content, '$.type') as type1,
                JSON_EXTRACT(m2.content, '$.type') as type2,
                r.relationship_type,
                COUNT(*) as frequency,
                AVG(r.strength) as avg_strength
            FROM memory_relationships r
            JOIN memory_nodes m1 ON r.source_node_id = m1.id
            JOIN memory_nodes m2 ON r.target_node_id = m2.id
            WHERE m1.tenant_id = ? AND m2.tenant_id = ?
            GROUP BY type1, type2, r.relationship_type
            HAVING frequency > 5
            ORDER BY frequency DESC
            LIMIT 20
        ", [$tenantId, $tenantId]);

        return array_map(function ($pattern) {
            return [
                'source_type' => $pattern->type1,
                'target_type' => $pattern->target_type,
                'relationship' => $pattern->relationship_type,
                'frequency' => $pattern->frequency,
                'avg_strength' => $pattern->avg_strength,
            ];
        }, $patterns);
    }

    /**
     * Consolidate and optimize memory over time
     */
    public function consolidate(string $tenantId): array
    {
        $consolidated = 0;
        $compressed = 0;

        // Find similar memories to consolidate
        $similarGroups = DB::select("
            SELECT
                m1.id as id1, m2.id as id2,
                (m1.vector_embedding <=> m2.vector_embedding) as similarity
            FROM memory_nodes m1
            JOIN memory_nodes m2 ON m1.tenant_id = m2.tenant_id
            WHERE m1.id < m2.id
            AND m1.tenant_id = ?
            AND (m1.vector_embedding <=> m2.vector_embedding) > 0.95
            AND m1.created_at < m2.created_at
        ", [$tenantId]);

        foreach ($similarGroups as $group) {
            // Consolidate similar memories
            $this->mergeMemories($group->id1, $group->id2);
            $consolidated++;
        }

        // Compress old, low-importance memories
        $oldMemories = DB::table('memory_nodes')
            ->where('tenant_id', $tenantId)
            ->where('importance_score', '<', 3)
            ->where('created_at', '<', now()->subMonths(6))
            ->get();

        foreach ($oldMemories as $memory) {
            $this->compressMemory($memory->id);
            $compressed++;
        }

        return [
            'consolidated' => $consolidated,
            'compressed' => $compressed,
            'total_processed' => $consolidated + $compressed,
        ];
    }

    /**
     * Generate vector embedding for text
     */
    private function generateEmbedding(string $text): array
    {
        // Call embedding service (OpenAI, local model, etc.)
        // This is a placeholder - implement based on your embedding service
        try {
            $response = \Illuminate\Support\Facades\Http::post(config('services.embedding.url'), [
                'text' => $text,
                'model' => 'text-embedding-ada-002',
            ]);

            return $response->json()['embedding'] ?? array_fill(0, self::VECTOR_DIMENSION, 0.0);
        } catch (\Exception $e) {
            Log::warning('Embedding generation failed, using zero vector', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text)
            ]);

            return array_fill(0, self::VECTOR_DIMENSION, 0.0);
        }
    }

    /**
     * Calculate importance score for memory
     */
    private function calculateImportance(array $content, array $metadata): float
    {
        $score = 1.0;

        // Content-based scoring
        if (isset($content['outcome']) && $content['outcome'] === 'success') {
            $score += 2.0;
        }

        if (isset($content['cost_savings']) && $content['cost_savings'] > 0) {
            $score += min($content['cost_savings'] / 100, 3.0);
        }

        // Metadata-based scoring
        if (isset($metadata['user_feedback']) && $metadata['user_feedback'] > 0) {
            $score += $metadata['user_feedback'] / 10;
        }

        if (isset($metadata['usage_frequency']) && $metadata['usage_frequency'] > 10) {
            $score += 1.0;
        }

        return min($score, 10.0);
    }

    /**
     * Merge two similar memories
     */
    private function mergeMemories(string $keepId, string $mergeId): void
    {
        // Transfer relationships
        DB::table('memory_relationships')
            ->where('source_node_id', $mergeId)
            ->update(['source_node_id' => $keepId]);

        DB::table('memory_relationships')
            ->where('target_node_id', $mergeId)
            ->update(['target_node_id' => $keepId]);

        // Mark as merged and inactive
        DB::table('memory_nodes')
            ->where('id', $mergeId)
            ->update([
                'is_active' => false,
                'metadata' => DB::raw("JSON_SET(metadata, '$.merged_into', '$keepId')"),
                'updated_at' => now(),
            ]);
    }

    /**
     * Compress old memory to save space
     */
    private function compressMemory(string $memoryId): void
    {
        $memory = DB::table('memory_nodes')->find($memoryId);
        if (!$memory) return;

        $content = json_decode($memory->content, true);
        $metadata = json_decode($memory->metadata, true);

        // Create compressed version
        $compressed = [
            'original_type' => $content['type'] ?? 'unknown',
            'summary' => $this->summarizeContent($content),
            'key_outcomes' => $content['outcomes'] ?? [],
            'compressed_at' => now()->toIso8601String(),
        ];

        DB::table('memory_nodes')
            ->where('id', $memoryId)
            ->update([
                'content' => json_encode($compressed),
                'metadata' => DB::raw("JSON_SET(metadata, '$.compressed', true)"),
                'updated_at' => now(),
            ]);
    }

    /**
     * Summarize memory content for compression
     */
    private function summarizeContent(array $content): string
    {
        $text = $content['description'] ?? $content['text'] ?? json_encode($content);
        return strlen($text) > 200 ? substr($text, 0, 200) . '...' : $text;
    }
}