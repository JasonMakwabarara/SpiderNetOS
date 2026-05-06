<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Auth/agents/onboarding contract tests — SQLite-compatible (no pgvector).
 */
class AuthSanctumContractTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Test Tenant',
            'slug' => 'test-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
        ]);
    }

    private function createUser(Tenant $tenant, bool $onboarded = true): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'user-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => $onboarded ? now() : null,
        ]);
    }

    public function test_login_returns_token(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $response->assertJsonPath('token', fn ($val) => $val !== null);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/auth/me');

        $response->assertOk();
        $response->assertJsonPath('data.email', $user->email);
    }

    public function test_agents_index_returns_tenant_scoped_agents(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        Agent::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Agent',
            'slug' => 'test-agent',
            'type' => 'static',
            'status' => 'active',
            'capabilities' => ['chat'],
            'config' => null,
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/agents');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('test-agent', $data[0]['slug']);
    }

    public function test_agents_cross_tenant_isolation(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userA = $this->createUser($tenantA);

        Agent::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Foreign Agent',
            'slug' => 'foreign-agent',
            'type' => 'static',
            'status' => 'active',
            'capabilities' => ['chat'],
            'config' => null,
        ]);

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/agents');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    public function test_onboarding_required_blocks_unfinished_users(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, onboarded: false);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/agents');

        $response->assertForbidden();
    }

    public function test_onboarding_complete_flow(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, onboarded: false);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/onboarding/complete', []);

        $response->assertOk();

        $user->refresh();
        $this->assertNotNull($user->onboarding_completed_at);
    }
}
