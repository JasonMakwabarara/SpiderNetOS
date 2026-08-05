<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Enterprise self-serve funnel — the six endpoints RegisterWizard.jsx
 * calls, previously only served by the FastAPI mock.
 */
class EnterpriseRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('enterprise.self_serve_enabled', true);
        config()->set('enterprise.auto_verify_domains', true);
    }

    private function startRegistration(): array
    {
        $response = $this->postJson('/api/enterprise/register/start', [
            'org_name' => 'Acme Robotics',
            'contact_email' => 'ops@acme-robotics.test',
            'contact_name' => 'Ada Ops',
            'domain' => 'acme-robotics.test',
        ]);

        $response->assertOk();

        return $response->json();
    }

    public function test_full_funnel_creates_real_tenant_and_admin(): void
    {
        $start = $this->startRegistration();

        $this->assertStringStartsWith('ent_', $start['enterprise_id']);
        $this->assertSame('acme-robotics.test', $start['domain']);
        $this->assertStringStartsWith('sn_', $start['domain_token']);

        $this->postJson('/api/enterprise/register/verify-domain', [
            'enterprise_id' => $start['enterprise_id'],
            'method' => 'auto',
        ])->assertOk()->assertJson(['verified' => true]);

        $tenantResponse = $this->postJson('/api/enterprise/register/create-tenant', [
            'enterprise_id' => $start['enterprise_id'],
            'region' => 'eu-west-2',
        ]);

        $tenantResponse->assertOk();
        $tenantId = $tenantResponse->json('tenant.id');

        $tenant = Tenant::find($tenantId);
        $this->assertNotNull($tenant);
        $this->assertSame('enterprise', $tenant->plan);
        $this->assertSame('active', $tenant->status);
        $this->assertSame('eu-west-2', $tenant->settings['region']);

        $admin = User::query()->where('tenant_id', $tenantId)->first();
        $this->assertNotNull($admin);
        $this->assertSame('ops@acme-robotics.test', $admin->email);
        $this->assertSame('admin', $admin->role);

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => $tenantId,
            'event_type' => 'tenant.created',
        ]);
    }

    public function test_create_tenant_is_idempotent_per_registration(): void
    {
        $start = $this->startRegistration();

        $first = $this->postJson('/api/enterprise/register/create-tenant', [
            'enterprise_id' => $start['enterprise_id'],
        ])->json('tenant.id');

        $second = $this->postJson('/api/enterprise/register/create-tenant', [
            'enterprise_id' => $start['enterprise_id'],
        ])->json('tenant.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_scim_token_returned_once_and_stored_hashed(): void
    {
        $start = $this->startRegistration();
        $tenantId = $this->postJson('/api/enterprise/register/create-tenant', [
            'enterprise_id' => $start['enterprise_id'],
        ])->json('tenant.id');

        $response = $this->postJson('/api/enterprise/register/scim/generate', [
            'enterprise_id' => $start['enterprise_id'],
            'tenant_id' => $tenantId,
        ]);

        $response->assertOk();
        $raw = $response->json('scim_token');
        $this->assertNotEmpty($raw);

        $stored = DB::table('enterprise_registrations')->where('id', $start['enterprise_id'])->value('scim_token_hash');
        $this->assertSame(hash('sha256', $raw), $stored);
    }

    public function test_bundle_and_deploy_are_recorded_as_queued(): void
    {
        $start = $this->startRegistration();
        $tenantId = $this->postJson('/api/enterprise/register/create-tenant', [
            'enterprise_id' => $start['enterprise_id'],
        ])->json('tenant.id');

        $bundle = $this->postJson('/api/enterprise/register/bundle/create', [
            'enterprise_id' => $start['enterprise_id'],
            'tenant_id' => $tenantId,
            'target' => 'linux-x86_64',
            'components' => ['runtime', 'connectors'],
        ]);

        $bundle->assertOk()->assertJson(['status' => 'queued']);
        $bundleId = $bundle->json('bundle_id');

        $deploy = $this->postJson('/api/enterprise/register/deploy/start', [
            'bundle_id' => $bundleId,
        ]);

        $deploy->assertOk()->assertJson(['status' => 'queued']);
        $this->assertDatabaseHas('aios_deployments', ['bundle_id' => $bundleId]);
    }

    public function test_start_without_domain_falls_back_to_email_domain(): void
    {
        $response = $this->postJson('/api/enterprise/register/start', [
            'org_name' => 'No Domain Co',
            'contact_email' => 'ops@nodomain-co.test',
        ]);

        $response->assertOk();
        $this->assertSame('nodomain-co.test', $response->json('domain'));
    }

    public function test_kill_switch_disables_funnel(): void
    {
        config()->set('enterprise.self_serve_enabled', false);

        $this->postJson('/api/enterprise/register/start', [
            'org_name' => 'Acme',
            'contact_email' => 'a@b.test',
        ])->assertStatus(503);
    }

    public function test_unknown_enterprise_id_is_404(): void
    {
        $this->postJson('/api/enterprise/register/create-tenant', [
            'enterprise_id' => 'ent_doesnotexist',
        ])->assertStatus(404);
    }
}
