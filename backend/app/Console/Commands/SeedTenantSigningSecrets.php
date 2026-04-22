<?php

namespace App\Console\Commands;

use App\Services\TenantKeyManager;
use Illuminate\Console\Command;

class SeedTenantSigningSecrets extends Command
{
    protected $signature = 'tenants:seed-signing-secrets';
    protected $description = 'Seed active tenant event_signing secrets for all tenants missing one';

    public function handle(TenantKeyManager $keyManager): int
    {
        $created = $keyManager->seedMissingSigningKeys();
        $this->info("Seeded {$created} tenant signing secret(s).");
        return self::SUCCESS;
    }
}
