<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Agent;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_cannot_access_another_tenant_agent()
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        $user1 = User::factory()->create(['tenant_id' => $tenant1->id]);
        $user2 = User::factory()->create(['tenant_id' => $tenant2->id]);

        $agent = Agent::factory()->create(['tenant_id' => $tenant1->id]);

        $this->actingAs($user2);
        $response = $this->getJson("/api/agents/{$agent->id}");

        $response->assertStatus(404);
    }

    public function test_tenant_can_access_own_agent()
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $agent = Agent::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($user);
        $response = $this->getJson("/api/agents/{$agent->id}");

        $response->assertStatus(200);
        $response->assertJson(['id' => $agent->id]);
    }
}
