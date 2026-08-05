<?php

namespace App\Console\Commands;

use App\Services\TenantKeyManager;
use Illuminate\Console\Command;

class RotateTenantKey extends Command
{
    protected $signature = 'tenants:rotate-key {tenant : Tenant UUID} {--grace-hours=24 : Grace window for previous key verification}';
    protected $description = 'Rotate tenant event signing key with dual-sign grace window';

    public function handle(TenantKeyManager $keyManager): int
    {
        $tenantId = (string) $this->argument('tenant');
        $graceHours = max(1, (int) $this->option('grace-hours'));

        $result = $keyManager->rotateSigningKey($tenantId, $graceHours);

        $this->info('Tenant signing key rotated.');
        $this->line('tenant_id: ' . $tenantId);
        $this->line('new_secret_id: ' . $result['new_secret_id']);
        $this->line('previous_secret_id: ' . ($result['previous_secret_id'] ?? 'none'));
        $this->line('grace_until: ' . ($result['grace_until'] ?? 'n/a'));

        return self::SUCCESS;
    }
}
