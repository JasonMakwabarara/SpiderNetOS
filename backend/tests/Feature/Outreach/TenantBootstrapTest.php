<?php

declare(strict_types=1);

namespace Tests\Feature\Outreach;

use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * outreach:tenant, the one-command path that turns a slug into a tenant
 * whose /api/sales routes are open without a Dodo purchase.
 */
class TenantBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_creates_everything_and_is_idempotent(): void
    {
        $args = ['slug' => 'hannah-ai', '--name' => 'Hannah AI', '--admin-email' => 'ops@hannah-ai.test'];

        $this->artisan('outreach:tenant', $args)->assertSuccessful();
        $this->artisan('outreach:tenant', $args)->assertSuccessful();

        $this->assertSame(1, Tenant::where('slug', 'hannah-ai')->count());
        $tenant = Tenant::where('slug', 'hannah-ai')->firstOrFail();

        $this->assertSame('Hannah AI', $tenant->name);
        $this->assertSame('active', $tenant->status);
        $this->assertSame('assisted', $tenant->automation_level);
        $this->assertNotNull($tenant->onboarding_completed_at);
        foreach (['tenant', 'budget', 'invites', 'strictness', 'branding'] as $step) {
            $this->assertArrayHasKey($step, $tenant->onboarding, "onboarding step {$step} missing");
        }

        // Outreach settings seeded from defaults.
        $outreach = $tenant->settings['outreach'];
        $this->assertSame(30, $outreach['program']['commission_pct']);
        $this->assertSame(12, $outreach['program']['months']);
        $this->assertSame('approve', $outreach['replies']['mode']);
        $this->assertSame('partner.invite', $outreach['sequence'][0]['template']);

        // Exactly one admin, onboarding complete so the API gate opens.
        $this->assertSame(1, User::where('email', 'ops@hannah-ai.test')->count());
        $admin = User::where('email', 'ops@hannah-ai.test')->firstOrFail();
        $this->assertSame((string) $tenant->id, (string) $admin->tenant_id);
        $this->assertSame('admin', $admin->role);
        $this->assertNotNull($admin->onboarding_completed_at);

        // Entitlement granted without a purchase, exactly once.
        $this->assertDatabaseHas('pack_entitlements', [
            'tenant_id' => $tenant->id, 'pack_id' => 'sales-crm', 'status' => 'active', 'source' => 'granted',
        ]);
        $this->assertSame(1, PackEntitlement::forTenant((string) $tenant->id)->where('pack_id', 'sales-crm')->active()->count());

        // Budget row (else CostGovernor falls back to env) and signing key.
        $this->assertSame(1, DB::table('cost_budgets')->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('tenant_secrets', ['tenant_id' => $tenant->id, 'key_name' => 'event_signing', 'active' => 1]);

        // Pack agents exist but stay inactive: the Python worker must not pick
        // up sales_crm_crm for this tenant (its reply loop is not used here).
        $agents = DB::table('agents')->where('tenant_id', $tenant->id)->get();
        $this->assertGreaterThan(0, $agents->count());
        foreach ($agents as $agent) {
            $this->assertSame('inactive', $agent->status, "agent {$agent->slug} should be inactive");
        }

        // The whole point: the pack-gated sales API answers for this tenant.
        $this->actingAs($admin, 'sanctum')->getJson('/api/sales/leads')->assertOk();
    }

    public function test_rerun_updates_automation_level_and_never_overwrites_settings(): void
    {
        $this->artisan('outreach:tenant', ['slug' => 'hannah-ai', '--automation-level' => 'assisted'])->assertSuccessful();

        $tenant = Tenant::where('slug', 'hannah-ai')->firstOrFail();
        $settings = $tenant->settings;
        $settings['outreach']['program']['commission_pct'] = 25;
        $settings['outreach']['program']['postal_address'] = '1 Example Street';
        $tenant->settings = $settings;
        $tenant->save();

        $this->artisan('outreach:tenant', ['slug' => 'hannah-ai', '--automation-level' => 'autonomous', '--skip-pack' => true])
            ->assertSuccessful();

        $tenant->refresh();
        $this->assertSame('autonomous', $tenant->automation_level);
        $this->assertSame(25, $tenant->settings['outreach']['program']['commission_pct']);
        $this->assertSame('1 Example Street', $tenant->settings['outreach']['program']['postal_address']);
    }

    public function test_rejects_bad_automation_level(): void
    {
        $this->artisan('outreach:tenant', ['slug' => 'hannah-ai', '--automation-level' => 'yolo'])->assertFailed();
        $this->assertSame(0, Tenant::where('slug', 'hannah-ai')->count());
    }

    public function test_admin_users_are_scoped_per_tenant(): void
    {
        $this->artisan('outreach:tenant', ['slug' => 'other-co', '--admin-email' => 'shared@example.test', '--skip-pack' => true])
            ->assertSuccessful();
        $this->artisan('outreach:tenant', ['slug' => 'hannah-ai', '--admin-email' => 'shared@example.test', '--skip-pack' => true])
            ->assertSuccessful();

        // users are unique per (tenant, email): one admin row per tenant.
        $this->assertSame(2, User::where('email', 'shared@example.test')->count());
        $this->assertSame(1, User::where('email', 'shared@example.test')
            ->where('tenant_id', Tenant::where('slug', 'hannah-ai')->value('id'))->count());
    }
}
