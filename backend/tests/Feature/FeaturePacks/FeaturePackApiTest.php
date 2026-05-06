<?php

declare(strict_types=1);

namespace Tests\Feature\FeaturePacks;

use App\Models\FeaturePack;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeaturePackApiTest extends TestCase
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
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'test@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createPack(Tenant $tenant, array $overrides = []): FeaturePack
    {
        $defaults = [
            'pack_id' => 'real-estate-crm',
            'version' => '0.1.0',
            'vertical' => 'real_estate',
            'display_name' => 'Real Estate CRM',
            'description' => 'Lead capture and management',
            'status' => 'installed',
            'installed_at' => now(),
            'manifest' => [
                'apiVersion' => 'spidernet/v1',
                'kind' => 'FeaturePack',
                'metadata' => [
                    'id' => 'real-estate-crm',
                    'version' => '0.1.0',
                    'vertical' => 'real_estate',
                ],
                'spec' => [
                    'provides' => [
                        'dynamic_agents' => [
                            ['id' => 'growth', 'displayName' => 'Growth Agent', 'capabilities' => ['lead_gen']],
                        ],
                        'flows' => [
                            ['id' => 'lead-capture', 'description' => 'Capture leads'],
                        ],
                    ],
                ],
            ],
        ];

        return FeaturePack::create(array_merge($defaults, $overrides));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/feature-packs');
        $response->assertUnauthorized();
    }

    public function test_lists_installed_packs_for_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->createPack($tenant);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/feature-packs');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.pack_id', 'real-estate-crm');
        $response->assertJsonPath('data.0.vertical', 'real_estate');
    }

    public function test_filters_by_vertical(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->createPack($tenant);
        $this->createPack($tenant, [
            'id' => Str::uuid(),
            'pack_id' => 'healthcare-crm',
            'vertical' => 'healthcare',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/feature-packs?vertical=real_estate');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.pack_id', 'real-estate-crm');
    }

    public function test_single_pack_details(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->createPack($tenant);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/feature-packs/real-estate-crm');

        $response->assertOk();
        $response->assertJsonPath('data.pack_id', 'real-estate-crm');
        $response->assertJsonPath('data.manifest.apiVersion', 'spidernet/v1');
        $response->assertJsonPath('data.agents.0.id', 'growth');
        $response->assertJsonPath('data.flows.0.id', 'lead-capture');
    }

    public function test_cross_tenant_isolation(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userA = $this->createUser($tenantA);
        $userB = $this->createUser($tenantB);
        $this->createPack($tenantA);
        $this->createPack($tenantB, [
            'id' => Str::uuid(),
            'pack_id' => 'healthcare-crm',
            'vertical' => 'healthcare',
        ]);

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/feature-packs');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.pack_id', 'real-estate-crm');

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/feature-packs/healthcare-crm');
        $response->assertNotFound();
    }

    public function test_empty_list_when_no_packs_installed(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/feature-packs');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
