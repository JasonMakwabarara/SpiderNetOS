<?php

namespace App\Console\Commands;

use App\Models\FeaturePack;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class SpidernetPackInstall extends Command
{
    protected $signature = 'spidernet:pack-install 
                            {pack_id : Pack directory name under packages/feature-packs/, e.g. real-estate-crm}
                            {--tenant= : Tenant UUID to install for (defaults to first active tenant)}
                            {--force : Replace existing staged copy in storage/app/feature-packs}';

    protected $description = 'Stage Feature Pack artefacts into storage/app/feature-packs/{id} from packages/feature-packs/{id}.';

    public function handle(): int
    {
        $id = strtolower((string) $this->argument('pack_id'));
        if ($id === '') {
            $this->error('pack_id is required.');

            return self::FAILURE;
        }

        $repoRoot = dirname(base_path());
        $src = $repoRoot.'/packages/feature-packs/'.$id;

        if (! is_dir($src)) {
            $this->error("Source pack directory not found: {$src}");

            return self::FAILURE;
        }

        $manifestPath = $src.'/pack.yaml';
        if (! is_readable($manifestPath)) {
            $this->error("Missing pack.yaml in {$src}");

            return self::FAILURE;
        }

        $destination = storage_path('app/feature-packs/'.$id);
        if (File::isDirectory($destination) && ! $this->option('force')) {
            $this->error("Destination exists: {$destination} (pass --force to replace)");

            return self::FAILURE;
        }

        if (File::isDirectory($destination)) {
            File::deleteDirectory($destination);
        }

        File::ensureDirectoryExists($destination);
        File::copyDirectory($src, $destination);

        $this->info("Copied pack {$id} to {$destination}");

        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->error('No tenant found. Specify --tenant=<id> or ensure an active tenant exists.');

            return self::FAILURE;
        }

        $manifest = Yaml::parseFile($manifestPath);

        $pack = FeaturePack::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'pack_id' => $id,
            ],
            [
                'version' => $manifest['metadata']['version'],
                'vertical' => $manifest['metadata']['vertical'],
                'display_name' => $manifest['metadata']['displayName'] ?? $id,
                'description' => $manifest['metadata']['description'] ?? null,
                'manifest' => $manifest,
                'status' => 'installed',
                'installed_at' => now(),
            ]
        );

        $this->info("Registered pack {$id} v{$manifest['metadata']['version']} for tenant {$tenant->id}");

        $this->provisionPackAgents($tenant, $manifest, $id);

        $this->line('Dynamic agent registration against the tenants table remains part of provisioning; inspect pack spec under docs/feature-packs/SPEC.md.');

        return self::SUCCESS;
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            return Tenant::where('id', $tenantId)->first();
        }

        return Tenant::where('status', 'active')->first();
    }

    private function provisionPackAgents(Tenant $tenant, array $manifest, string $packId): void
    {
        $agents = $manifest['spec']['provides']['dynamic_agents'] ?? [];

        if (empty($agents)) {
            $this->line('No dynamic agents defined in pack manifest.');

            return;
        }

        $count = 0;
        foreach ($agents as $agentDef) {
            $agentId = $agentDef['id'];
            $slug = Str::slug($agentDef['id'], '_');

            $existing = DB::table('agents')
                ->where('tenant_id', $tenant->id)
                ->where('slug', $slug)
                ->first();

            if (! $existing) {
                DB::table('agents')->insert([
                    'id' => Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'name' => $agentDef['displayName'] ?? $agentId,
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
        }

        if ($count > 0) {
            $this->info("Provisioned {$count} dynamic agent(s) for {$packId} pack.");
        } else {
            $this->line('All pack agents already provisioned.');
        }
    }
}
