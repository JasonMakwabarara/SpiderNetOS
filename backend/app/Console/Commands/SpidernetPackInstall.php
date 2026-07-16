<?php

namespace App\Console\Commands;

use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Services\EntitlementRequiredException;
use App\Services\FeaturePackInstaller;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class SpidernetPackInstall extends Command
{
    protected $signature = 'spidernet:pack-install
                            {pack_id : Pack directory name under packages/feature-packs/, e.g. real-estate-crm}
                            {--tenant= : Tenant UUID to install for (defaults to first active tenant)}
                            {--force : Replace existing staged copy in storage/app/feature-packs}
                            {--grant : Grant an entitlement for priced packs instead of requiring purchase (ops/testing)}';

    protected $description = 'Install a Feature Pack for a tenant via FeaturePackInstaller (same path as the HTTP API).';

    public function handle(FeaturePackInstaller $installer): int
    {
        $id = strtolower((string) $this->argument('pack_id'));
        if ($id === '') {
            $this->error('pack_id is required.');

            return self::FAILURE;
        }

        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->error('No tenant found. Specify --tenant=<id> or ensure an active tenant exists.');

            return self::FAILURE;
        }

        if ($this->option('grant')) {
            $this->grantEntitlement($tenant->id, $id);
        }

        try {
            $result = $installer->install($tenant, $id, (bool) $this->option('force'));
        } catch (EntitlementRequiredException $e) {
            $this->error("{$e->getMessage()} Re-run with --grant to bypass for ops/testing, or purchase it via the cockpit.");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Registered pack {$id} v{$result['version']} for tenant {$tenant->id}");
        $this->info("Provisioned/verified {$result['agents_provisioned']} dynamic agent(s).");

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

    private function grantEntitlement(string $tenantId, string $packId): void
    {
        $repoRoot = dirname(base_path());
        $manifestPath = $repoRoot.'/packages/feature-packs/'.$packId.'/pack.yaml';
        $pricing = is_readable($manifestPath) ? (Yaml::parseFile($manifestPath)['spec']['pricing'] ?? null) : null;

        PackEntitlement::updateOrCreate(
            ['tenant_id' => $tenantId, 'pack_id' => $packId, 'status' => 'active'],
            [
                'source' => 'granted',
                'status' => 'active',
                'amount_cents' => (int) round((float) ($pricing['amount'] ?? 0) * 100),
                'currency' => (string) ($pricing['currency'] ?? 'USD'),
                'purchased_at' => now(),
            ],
        );

        $this->info("Granted entitlement for {$packId} to tenant {$tenantId}.");
    }
}
