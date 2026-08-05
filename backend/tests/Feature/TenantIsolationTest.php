<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
        ]);
    }

    public function test_user_cannot_access_other_tenants_agent(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');

        $agent = Agent::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Private Agent',
            'slug' => 'private-agent',
            'type' => 'static',
            'status' => 'active',
            'capabilities' => ['chat'],
            'config' => null,
        ]);

        $userFromB = User::create([
            'name' => 'B User',
            'email' => 'b-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantB->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);

        // Cross-tenant access is a 404, not 403 — the resource's existence
        // must not be revealed to another tenant.
        $this->actingAs($userFromB, 'sanctum')
            ->getJson("/api/agents/{$agent->id}")
            ->assertNotFound();
    }
}
