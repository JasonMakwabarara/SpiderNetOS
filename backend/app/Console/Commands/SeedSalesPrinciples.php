<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds the sales-crm pack's sales-principles.yaml corpus into the tenant
 * memory graph (memory_nodes/memory_edges) so DynamicAgent conversation
 * handling can retrieve principle guidance as RAG context.
 *
 * - One 'insight' node per principle: statement + application + script
 *   guidance, embedded via the inference plane's POST /embed ({text} in,
 *   {embedding} out — see inference/main.py).
 * - memory_edges link principles sharing a category (related_principle).
 * - Idempotent per principle: rows keyed by metadata->principle_id are
 *   deleted (with their edges) and reinserted on every run.
 * - memory_nodes is pgvector-only (see 2024_01_01_000006_create_memory_table):
 *   on sqlite/other drivers, or when the table is absent, the command warns
 *   and exits cleanly.
 */
class SeedSalesPrinciples extends Command
{
    protected $signature = 'sales:seed-principles
                            {--tenant= : Seed a single tenant id (defaults to all active tenants)}';

    protected $description = 'Embed the sales-crm sales principles into memory_nodes/memory_edges for agent RAG retrieval';

    private const PACK_ID = 'sales-crm';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->warn('memory_nodes requires Postgres + pgvector — skipping (driver: '.DB::connection()->getDriverName().').');

            return self::SUCCESS;
        }

        try {
            $hasTable = Schema::hasTable('memory_nodes');
        } catch (\Throwable $e) {
            $this->warn('Database unreachable ('.$e->getMessage().') — skipping.');

            return self::SUCCESS;
        }

        if (! $hasTable) {
            $this->warn('memory_nodes table absent — run migrations against the Postgres stack first. Skipping.');

            return self::SUCCESS;
        }

        $principles = $this->loadPrinciples();
        if ($principles === []) {
            $this->error('No principles found in policies/sales-principles.yaml (staged or source tree).');

            return self::FAILURE;
        }

        $tenantIds = $this->option('tenant')
            ? [(string) $this->option('tenant')]
            : DB::table('tenants')->where('status', 'active')->pluck('id')->map(fn ($id) => (string) $id)->all();

        if ($tenantIds === []) {
            $this->warn('No tenants to seed.');

            return self::SUCCESS;
        }

        foreach ($tenantIds as $tenantId) {
            $seeded = $this->seedTenant($tenantId, $principles);
            $this->info("Tenant {$tenantId}: seeded {$seeded['nodes']} principle nodes, {$seeded['edges']} related_principle edges.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $principles
     * @return array{nodes: int, edges: int}
     */
    private function seedTenant(string $tenantId, array $principles): array
    {
        $nodeIdsByCategory = [];
        $nodes = 0;

        foreach ($principles as $principle) {
            $principleId = (string) ($principle['id'] ?? '');
            if ($principleId === '') {
                continue;
            }

            $category = (string) ($principle['category'] ?? 'general');
            $content = $this->principleText($principle);
            $embedding = $this->embed($content);
            if ($embedding === null) {
                $this->warn("  {$principleId}: embedding unavailable — storing node without a vector.");
            }

            // Idempotency: delete + reinsert per principle (and its edges).
            $stale = DB::table('memory_nodes')
                ->where('tenant_id', $tenantId)
                ->where('metadata->principle_id', $principleId)
                ->pluck('id');

            if ($stale->isNotEmpty()) {
                DB::table('memory_edges')->whereIn('source_id', $stale)->delete();
                DB::table('memory_edges')->whereIn('target_id', $stale)->delete();
                DB::table('memory_nodes')->whereIn('id', $stale)->delete();
            }

            $nodeId = (string) Str::uuid();
            DB::table('memory_nodes')->insert([
                'id' => $nodeId,
                'tenant_id' => $tenantId,
                'agent_id' => null,
                'node_type' => 'insight',
                'content' => $content,
                // pgvector text input format: '[v1,v2,...]' — coerced to
                // vector(384) by the column type on insert.
                'embedding' => $embedding !== null ? '['.implode(',', $embedding).']' : null,
                'metadata' => json_encode([
                    'source' => 'sales_manual',
                    'principle_id' => $principleId,
                    'category' => $category,
                    'name' => (string) ($principle['name'] ?? $principleId),
                ]),
                'recency_score' => 0,
                'importance_score' => 0.8,
                'access_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $nodes++;

            $nodeIdsByCategory[$category][] = $nodeId;
        }

        $edges = 0;
        foreach ($nodeIdsByCategory as $category => $nodeIds) {
            $countIds = count($nodeIds);
            for ($i = 0; $i < $countIds; $i++) {
                for ($j = $i + 1; $j < $countIds; $j++) {
                    DB::table('memory_edges')->insert([
                        'source_id' => $nodeIds[$i],
                        'target_id' => $nodeIds[$j],
                        'relation_type' => 'related_principle',
                        'weight' => 1.0,
                        'metadata' => json_encode(['source' => 'sales_manual', 'category' => $category]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $edges++;
                }
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * The chunk embedded per principle: statement + application + script
     * guidance, labelled so retrieval hits read coherently in agent context.
     *
     * @param  array<string, mixed>  $principle
     */
    private function principleText(array $principle): string
    {
        $parts = array_filter([
            trim((string) ($principle['name'] ?? '')),
            trim((string) ($principle['statement'] ?? '')),
            ($application = trim((string) ($principle['application'] ?? ''))) !== '' ? 'Application: '.$application : '',
            ($guidance = trim((string) ($principle['script_guidance'] ?? ''))) !== '' ? 'Script guidance: '.$guidance : '',
        ], fn ($part) => $part !== '');

        return implode("\n", $parts);
    }

    /**
     * POST {text} to the inference plane's /embed (see inference/main.py:
     * EmbedRequest{text, model?} -> EmbedResponse{embedding, model, dimensions}).
     *
     * @return array<int, float>|null
     */
    private function embed(string $text): ?array
    {
        $url = rtrim((string) config('services.inference.url', ''), '/');
        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('services.inference.timeout', 60))
                ->post($url.'/embed', ['text' => $text]);

            if (! $response->successful()) {
                return null;
            }

            $embedding = $response->json('embedding');

            return is_array($embedding) && $embedding !== [] ? array_map('floatval', $embedding) : null;
        } catch (\Throwable $e) {
            $this->warn('  /embed request failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Staged storage copy first, source-tree fallback (same dual-path rule as
     * FunnelSetupService::loadPackFile()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadPrinciples(): array
    {
        $path = storage_path('app/feature-packs/'.self::PACK_ID.'/policies/sales-principles.yaml');
        if (! is_readable($path)) {
            $path = dirname(base_path()).'/packages/feature-packs/'.self::PACK_ID.'/policies/sales-principles.yaml';
        }
        if (! is_readable($path)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter((array) ($parsed['principles'] ?? []), 'is_array'));
    }
}
