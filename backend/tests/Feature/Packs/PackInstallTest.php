<?php

declare(strict_types=1);

namespace Tests\Feature\Packs;

use App\Models\FeaturePack;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeaturePackInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres. Pack monetization: plan-included installs, the signed-pack
 * gate, and uninstall.
 */
class PackInstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::create([
            'id' => 'growth', 'name' => 'Growth', 'monthly_fee_cents' => 49900,
            'included_usage_cents' => 15000, 'usage_margin_pct' => 15, 'is_custom' => false,
            'entitlements' => ['agents' => 10, 'flows' => 50, 'seats' => 10, 'pack_slots' => 3],
            'is_active' => true, 'sort' => 2,
        ]);
    }

    private function tenant(): Tenant
    {
        // plan='growth' → PlanEntitlementService resolves growth (pack_slots 3).
        return Tenant::create([
            'id' => Str::uuid(), 'name' => 'Pack Co', 'slug' => 'pack-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
    }

    public function test_plan_included_pack_installs_without_purchase(): void
    {
        $tenant = $this->tenant();

        $result = app(FeaturePackInstaller::class)->install($tenant, 'sales-crm', true);

        $this->assertGreaterThan(0, $result['agents_provisioned']);
        // An included-in-plan entitlement was granted (no purchase needed).
        $this->assertDatabaseHas('pack_entitlements', [
            'tenant_id' => $tenant->id, 'pack_id' => 'sales-crm', 'status' => 'active', 'source' => 'included_in_plan',
        ]);
    }

    public function test_require_signed_rejects_placeholder_signature(): void
    {
        config()->set('feature_packs.require_signed', true);
        $tenant = $this->tenant();

        $this->expectException(\RuntimeException::class);
        app(FeaturePackInstaller::class)->install($tenant, 'sales-crm', true);
    }

    public function test_uninstall_removes_agents_but_keeps_entitlement(): void
    {
        $tenant = $this->tenant();
        app(FeaturePackInstaller::class)->install($tenant, 'sales-crm', true);

        $this->assertGreaterThan(0, DB::table('agents')->where('tenant_id', $tenant->id)->where('config->pack_id', 'sales-crm')->count());

        $user = User::create([
            'name' => 'Owner', 'email' => 'o@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'), 'tenant_id' => $tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->deleteJson('/api/feature-packs/sales-crm')
            ->assertOk()->assertJsonPath('data.uninstalled', true);

        $this->assertSame(0, DB::table('agents')->where('tenant_id', $tenant->id)->where('config->pack_id', 'sales-crm')->count());
        $this->assertSame(0, FeaturePack::where('tenant_id', $tenant->id)->where('pack_id', 'sales-crm')->count());
        // Ownership retained → reinstallable without re-purchase.
        $this->assertDatabaseHas('pack_entitlements', ['tenant_id' => $tenant->id, 'pack_id' => 'sales-crm', 'status' => 'active']);
    }
}
