<?php

declare(strict_types=1);

namespace Tests\Feature\FeaturePacks;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
        ]);
    }

    public function test_installs_pack_from_packages_directory(): void
    {
        $tenant = $this->createTenant();

        $this->artisan('spidernet:pack-install', [
            'pack_id' => 'real-estate-crm',
            '--tenant' => $tenant->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('feature_packs', [
            'tenant_id' => $tenant->id,
            'pack_id' => 'real-estate-crm',
            'version' => '0.1.0',
            'vertical' => 'real_estate',
        ]);

        $this->assertTrue(File::isDirectory(storage_path('app/feature-packs/real-estate-crm')));
    }

    public function test_fails_when_pack_directory_missing(): void
    {
        $this->artisan('spidernet:pack-install', [
            'pack_id' => 'nonexistent-pack',
        ])->assertFailed();

        $this->assertDatabaseMissing('feature_packs', [
            'pack_id' => 'nonexistent-pack',
        ]);
    }

    public function test_fails_when_no_tenant_specified_and_none_active(): void
    {
        $this->artisan('spidernet:pack-install', [
            'pack_id' => 'real-estate-crm',
        ])->assertFailed();
    }
}
