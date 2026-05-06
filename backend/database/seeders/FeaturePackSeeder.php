<?php

namespace Database\Seeders;

use App\Models\FeaturePack;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Symfony\Component\Yaml\Yaml;

class FeaturePackSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('status', 'active')->first();

        if (! $tenant) {
            $this->command->info('No active tenant found. Skipping feature pack seeder.');

            return;
        }

        $packPath = dirname(base_path()).'/packages/feature-packs/real-estate-crm/pack.yaml';

        if (! is_file($packPath)) {
            $this->command->warn('real-estate-crm pack.yaml not found. Skipping.');

            return;
        }

        $manifest = Yaml::parseFile($packPath);

        FeaturePack::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'pack_id' => 'real-estate-crm',
            ],
            [
                'version' => $manifest['metadata']['version'],
                'vertical' => $manifest['metadata']['vertical'],
                'display_name' => $manifest['metadata']['displayName'] ?? 'Real Estate CRM',
                'description' => $manifest['metadata']['description'] ?? null,
                'manifest' => $manifest,
                'status' => 'installed',
                'installed_at' => now(),
            ]
        );

        $this->command->info('Seeded real-estate-crm feature pack for tenant '.$tenant->id);
    }
}
