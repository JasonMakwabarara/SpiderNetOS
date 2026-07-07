<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cross-tenant isolation — the most important security property of the
 * platform. Every tenant-scoped read must 404 (not leak existence) when
 * the resource belongs to another tenant.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(string $name): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'starter',
        ]);
    }

    private function createUser(Tenant $tenant): User
    {
        return User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Isolation Tester',
            'email' => Str::lower(Str::random(10)) . '@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createAgent(Tenant $tenant): Agent
    {
        return Agent::create([
            'tenant_id' => $tenant->id,
            'name' => 'Tenant A Agent',
            'slug' => 'tenant-a-agent-' . Str::lower(Str::random(6)),
            'type' => 'assistant',
            'status' => 'active',
            'capabilities' => [],
            'config' => [],
        ]);
    }

    public function test_user_cannot_read_another_tenants_agent(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        $agent = $this->createAgent($tenantA);

        Sanctum::actingAs($this->createUser($tenantB));

        // Cross-tenant reads must 404 — a 403 would leak that the id exists.
        $this->getJson("/api/agents/{$agent->id}")->assertStatus(404);
    }

    public function test_user_can_read_own_tenants_agent(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $agent = $this->createAgent($tenantA);

        Sanctum::actingAs($this->createUser($tenantA));

        $this->getJson("/api/agents/{$agent->id}")->assertOk();
    }

    public function test_agent_listing_only_returns_own_tenant(): void
    {
        $tenantA = $this->createTenant('Tenant A');
        $tenantB = $this->createTenant('Tenant B');

        $this->createAgent($tenantA);

        Sanctum::actingAs($this->createUser($tenantB));

        $response = $this->getJson('/api/agents');

        $response->assertOk();
        $this->assertSame([], $response->json('data') ?? [], 'tenant B must not see tenant A agents');
    }

    public function test_inactive_tenant_is_rejected(): void
    {
        $tenant = $this->createTenant('Tenant A');
        $agent = $this->createAgent($tenant);
        $user = $this->createUser($tenant);

        $tenant->forceFill(['status' => 'suspended'])->save();

        Sanctum::actingAs($user);

        $this->getJson("/api/agents/{$agent->id}")->assertStatus(403);
    }
}
