<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\Agent;
use Laravel\Sanctum\Sanctum;

class TenantIsolationTest extends TestCase
{
    public function test_user_cannot_access_other_tenants_agent()
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $agent = Agent::factory()->for($tenantA)->create();

        $userFromB = User::factory()->for($tenantB)->create();
        Sanctum::actingAs($userFromB);

        $response = $this->getJson("/api/agents/{$agent->id}");

        $response->assertStatus(403);
    }
}
