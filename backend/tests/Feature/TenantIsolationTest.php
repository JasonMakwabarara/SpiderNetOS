<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Agent;
use App\Models\Flow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant1;
    protected $tenant2;
    protected $user1;
    protected $user2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two tenants
        $this->tenant1 = Tenant::create([
            'id' => '11111111-1111-1111-1111-111111111111',
            'name' => 'Tenant One',
            'slug' => 'tenant-one',
            'status' => 'active',
        ]);

        $this->tenant2 = Tenant::create([
            'id' => '22222222-2222-2222-2222-222222222222',
            'name' => 'Tenant Two',
            'slug' => 'tenant-two',
            'status' => 'active',
        ]);

        // Create users for each tenant
        $this->user1 = User::create([
            'id' => '11111111-1111-1111-1111-111111111112',
            'name' => 'User One',
            'email' => 'user1@tenant1.com',
            'password' => Hash::make('password'),
            'tenant_id' => $this->tenant1->id,
        ]);

        $this->user2 = User::create([
            'id' => '22222222-2222-2222-2222-222222222223',
            'name' => 'User Two',
            'email' => 'user2@tenant2.com',
            'password' => Hash::make('password'),
            'tenant_id' => $this->tenant2->id,
        ]);
    }

    /** @test */
    public function tenant_cannot_access_another_tenant_agents()
    {
        // Create an agent for tenant1
        $agent = Agent::create([
            'id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant One Agent',
            'slug' => 'tenant-one-agent',
            'type' => 'custom',
            'status' => 'active',
            'capabilities' => ['chat'],
        ]);

        // Act as user2 (tenant2) trying to access tenant1's agent
        $this->actingAs($this->user2);
        $response = $this->getJson("/api/agents/{$agent->id}");

        // Should return 404 (not found) because tenant2 cannot see tenant1's data
        $response->assertStatus(404);
    }

    /** @test */
    public function tenant_can_access_own_agents()
    {
        // Create an agent for tenant1
        $agent = Agent::create([
            'id' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant One Agent',
            'slug' => 'tenant-one-agent',
            'type' => 'custom',
            'status' => 'active',
            'capabilities' => ['chat'],
        ]);

        // Act as user1 (tenant1) accessing their own agent
        $this->actingAs($this->user1);
        $response = $this->getJson("/api/agents/{$agent->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $agent->id,
            'name' => 'Tenant One Agent',
        ]);
    }

    /** @test */
    public function tenant_cannot_access_another_tenant_flows()
    {
        // Create a flow for tenant1
        $flow = Flow::create([
            'id' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant One Flow',
            'slug' => 'tenant-one-flow',
            'status' => 'draft',
            'dag' => ['steps' => []],
            'triggers' => ['manual'],
        ]);

        // Act as user2 (tenant2) trying to access tenant1's flow
        $this->actingAs($this->user2);
        $response = $this->getJson("/api/flows/{$flow->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function tenant_can_access_own_flows()
    {
        // Create a flow for tenant1
        $flow = Flow::create([
            'id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant One Flow',
            'slug' => 'tenant-one-flow',
            'status' => 'draft',
            'dag' => ['steps' => []],
            'triggers' => ['manual'],
        ]);

        // Act as user1 (tenant1) accessing their own flow
        $this->actingAs($this->user1);
        $response = $this->getJson("/api/flows/{$flow->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'id' => $flow->id,
            'name' => 'Tenant One Flow',
        ]);
    }

    /** @test */
    public function tenant_cannot_list_another_tenant_agents()
    {
        // Create agents for both tenants
        Agent::create([
            'id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant One Agent',
            'slug' => 'tenant-one-agent',
            'type' => 'custom',
            'status' => 'active',
            'capabilities' => ['chat'],
        ]);

        Agent::create([
            'id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'tenant_id' => $this->tenant2->id,
            'name' => 'Tenant Two Agent',
            'slug' => 'tenant-two-agent',
            'type' => 'custom',
            'status' => 'active',
            'capabilities' => ['chat'],
        ]);

        // Act as user1 (tenant1) listing agents
        $this->actingAs($this->user1);
        $response = $this->getJson("/api/agents");

        $response->assertStatus(200);
        $data = $response->json();

        // Should only see tenant1's agent (not tenant2's)
        $this->assertCount(1, $data);
        $this->assertEquals('Tenant One Agent', $data[0]['name']);
    }

    /** @test */
    public function tenant_cannot_list_another_tenant_flows()
    {
        // Create flows for both tenants
        Flow::create([
            'id' => 'gggggggg-gggg-gggg-gggg-gggggggggggg',
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant One Flow',
            'slug' => 'tenant-one-flow',
            'status' => 'draft',
            'dag' => ['steps' => []],
            'triggers' => ['manual'],
        ]);

        Flow::create([
            'id' => 'hhhhhhhh-hhhh-hhhh-hhhh-hhhhhhhhhhhh',
            'tenant_id' => $this->tenant2->id,
            'name' => 'Tenant Two Flow',
            'slug' => 'tenant-two-flow',
            'status' => 'draft',
            'dag' => ['steps' => []],
            'triggers' => ['manual'],
        ]);

        // Act as user1 (tenant1) listing flows
        $this->actingAs($this->user1);
        $response = $this->getJson("/api/flows");

        $response->assertStatus(200);
        $data = $response->json();

        // Should only see tenant1's flow (not tenant2's)
        $this->assertCount(1, $data);
        $this->assertEquals('Tenant One Flow', $data[0]['name']);
    }

    /** @test */
    public function tenant_can_create_agent_with_own_tenant_id()
    {
        $this->actingAs($this->user1);
        $response = $this->postJson('/api/agents', [
            'name' => 'New Agent',
            'description' => 'Test agent',
            'capabilities' => ['chat'],
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        // The agent should be created with tenant1's tenant_id
        $this->assertEquals($this->tenant1->id, $data['tenant_id']);
    }

    /** @test */
    public function tenant_cannot_create_agent_with_different_tenant_id()
    {
        $this->actingAs($this->user1);
        $response = $this->postJson('/api/agents', [
            'name' => 'Malicious Agent',
            'description' => 'Trying to create agent for different tenant',
            'tenant_id' => $this->tenant2->id, // Trying to use tenant2's ID
            'capabilities' => ['chat'],
        ]);

        $response->assertStatus(201);
        $data = $response->json();

        // The agent should still be created with tenant1's tenant_id (not tenant2's)
        $this->assertEquals($this->tenant1->id, $data['tenant_id']);
        $this->assertNotEquals($this->tenant2->id, $data['tenant_id']);
    }
}
