<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeaturePack;
use App\Models\PackEntitlement;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Installs a feature pack for a tenant (shared by CLI and HTTP API).
 */
class FeaturePackInstaller
{
    public function install(Tenant $tenant, string $packId, bool $force = false): array
    {
        $id = strtolower(trim($packId));
        if ($id === '') {
            throw new \InvalidArgumentException('pack_id is required.');
        }

        $src = $this->packsRoot().'/'.$id;

        if (! is_dir($src)) {
            throw new \RuntimeException("Pack directory not found: {$id}");
        }

        $manifestPath = $src.'/pack.yaml';
        if (! is_readable($manifestPath)) {
            throw new \RuntimeException("Missing pack.yaml for pack: {$id}");
        }

        $destination = storage_path('app/feature-packs/'.$id);
        if (File::isDirectory($destination) && ! $force) {
            // Already staged — continue with DB registration
        } else {
            if (File::isDirectory($destination)) {
                File::deleteDirectory($destination);
            }
            File::ensureDirectoryExists($destination);
            File::copyDirectory($src, $destination);
        }

        $manifest = Yaml::parseFile($manifestPath);
        $meta = $manifest['metadata'] ?? [];

        $this->assertEntitled($tenant, $id, $manifest);

        $pack = FeaturePack::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'pack_id' => $id,
            ],
            [
                'version' => $meta['version'] ?? '0.0.0',
                'vertical' => $meta['vertical'] ?? 'general',
                'display_name' => $meta['displayName'] ?? $meta['display_name'] ?? $id,
                'description' => $meta['description'] ?? null,
                'manifest' => $manifest,
                'status' => 'installed',
                'installed_at' => now(),
            ]
        );

        $agentsProvisioned = $this->provisionPackAgents($tenant, $manifest, $id);

        return [
            'pack_id' => $id,
            'version' => $pack->version,
            'display_name' => $pack->display_name,
            'status' => $pack->status,
            'agents_provisioned' => $agentsProvisioned,
            'entry_path' => $this->entryPathForPack($id),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     *
     * @throws EntitlementRequiredException
     */
    private function assertEntitled(Tenant $tenant, string $packId, array $manifest): void
    {
        $pricing = $manifest['spec']['pricing'] ?? null;
        if (! $pricing) {
            return; // Free pack — no entitlement required.
        }

        $hasActiveEntitlement = PackEntitlement::forTenant($tenant->id)
            ->where('pack_id', $packId)
            ->active()
            ->exists();

        if ($hasActiveEntitlement) {
            return;
        }

        $amountCents = (int) round((float) ($pricing['amount'] ?? 0) * 100);
        throw new EntitlementRequiredException($packId, $amountCents, (string) ($pricing['currency'] ?? 'USD'));
    }

    private function entryPathForPack(string $packId): string
    {
        return match ($packId) {
            'financial-services' => '/financial',
            'sales-crm' => '/sales',
            'compliance-radar' => '/compliance',
            default => '/feature-packs',
        };
    }

    private function provisionPackAgents(Tenant $tenant, array $manifest, string $packId): int
    {
        $agents = $manifest['spec']['provides']['dynamic_agents'] ?? [];
        $count = 0;

        foreach ($agents as $agentDef) {
            $agentId = $agentDef['id'] ?? null;
            if (! $agentId) {
                continue;
            }

            // Namespaced per docs/feature-packs/SPEC.md isolation rule #3, so
            // packs that reuse common agent ids (e.g. "growth", "crm") never
            // collide when a tenant installs more than one pack.
            $slug = Str::slug($packId, '_').'_'.Str::slug((string) $agentId, '_');

            $exists = DB::table('agents')
                ->where('tenant_id', $tenant->id)
                ->where('slug', $slug)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('agents')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'name' => $agentDef['displayName'] ?? $agentDef['name'] ?? $agentId,
                'slug' => $slug,
                'description' => "Provisioned from {$packId} pack",
                'type' => 'dynamic',
                'status' => 'inactive',
                'capabilities' => json_encode($agentDef['capabilities'] ?? []),
                'config' => json_encode(['pack_id' => $packId]),
                'activated_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    private function packsRoot(): string
    {
        return rtrim((string) env('FEATURE_PACKS_ROOT', dirname(base_path()).'/packages/feature-packs'), '/');
    }
}
