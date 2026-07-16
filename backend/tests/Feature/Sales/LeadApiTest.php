<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Lead;
use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres + pgvector (see database/migrations/2024_01_01_000006_create_memory_table.php)
 * — not runnable against the sqlite :memory: connection. Run via the
 * project's Docker Postgres stack, not the CiFast suite.
 */
class LeadApiTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);

        // /api/sales/* is gated by pack.entitled:sales-crm — grant the pack so
        // the authenticated sales routes are reachable in tests.
        PackEntitlement::create([
            'tenant_id' => $tenant->id,
            'pack_id' => 'sales-crm',
            'source' => 'grant',
            'provider' => 'manual',
            'status' => 'active',
            'purchased_at' => now(),
        ]);

        return $tenant;
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

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/sales/leads')->assertUnauthorized();
    }

    public function test_creates_lead_with_default_consent(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/sales/leads', [
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'source' => 'referral',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.stage', 'captured');
        $this->assertDatabaseHas('leads', ['tenant_id' => $tenant->id, 'email' => 'jamie@example.com', 'stage' => 'captured']);
    }

    public function test_rejects_lead_with_no_contact_method(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/sales/leads', ['name' => 'No Contact']);

        $response->assertStatus(422);
    }

    public function test_stage_transition_emits_matching_event(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $lead = Lead::create([
            'tenant_id' => $tenant->id, 'email' => 'won@example.com', 'stage' => 'captured',
            'score' => 50, 'consent' => ['email_opt_in' => true, 'whatsapp_opt_in' => true, 'opted_out_at' => null],
        ]);

        $response = $this->actingAs($user, 'sanctum')->postJson("/api/sales/leads/{$lead->id}/stage", ['stage' => 'won']);

        $response->assertOk();
        $response->assertJsonPath('data.stage', 'won');
        $this->assertDatabaseHas('event_log', [
            'tenant_id' => $tenant->id,
            'aggregate_id' => $lead->id,
            'event_type' => 'deal.won',
        ]);
    }

    public function test_cross_tenant_isolation(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userA = $this->createUser($tenantA);
        Lead::create(['tenant_id' => $tenantB->id, 'email' => 'other@example.com', 'stage' => 'captured', 'score' => 10, 'consent' => []]);

        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/sales/leads');

        $response->assertOk();
        $response->assertJsonCount(0, 'data.data');
    }

    public function test_public_lead_capture_requires_a_contact_method(): void
    {
        $tenant = $this->createTenant();

        $response = $this->postJson("/api/public/lead-capture/{$tenant->id}", ['name' => 'Anonymous']);

        $response->assertStatus(422);
    }

    public function test_public_lead_capture_creates_lead_without_auth(): void
    {
        $tenant = $this->createTenant();

        $response = $this->postJson("/api/public/lead-capture/{$tenant->id}", [
            'name' => 'Web Visitor',
            'email' => 'visitor@example.com',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('leads', ['tenant_id' => $tenant->id, 'email' => 'visitor@example.com', 'source' => 'landing_page']);
    }

    public function test_public_lead_capture_rejects_unknown_tenant(): void
    {
        $response = $this->postJson('/api/public/lead-capture/'.Str::uuid(), ['email' => 'x@example.com']);

        $response->assertNotFound();
    }
}
