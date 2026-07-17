<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Requires Postgres — exercises GeneratePlatformInvoices against real
 * usage_daily_aggregates rows.
 */
class InvoiceGenerationTest extends TestCase
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

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(), 'name' => 'Inv Co', 'slug' => 'inv-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
    }

    private function seedUsage(string $tenantId, string $date, string $resource, float $cost): void
    {
        DB::table('usage_daily_aggregates')->insert([
            'tenant_id' => $tenantId, 'date' => $date, 'resource_type' => $resource,
            'total_calls' => 1, 'total_tokens' => 1000, 'total_cost' => $cost,
            'cost_ceiling' => 0, 'calculated_at' => now(),
        ]);
    }

    public function test_generates_invoice_with_marked_up_overage(): void
    {
        $tenant = $this->makeTenant();
        TenantSubscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => 'growth', 'status' => 'active', 'dodo_subscription_id' => 'sub_z',
        ]);

        $period = now()->subMonthNoOverflow();
        $day = $period->copy()->startOfMonth()->addDays(5)->toDateString();
        // $200 total usage vs $150 included → $50 over, +15% = $57.50 overage.
        $this->seedUsage($tenant->id, $day, 'llm', 120.00);
        $this->seedUsage($tenant->id, $day, 'tools', 80.00);

        $this->artisan('spidernet:billing:generate-invoices', ['--period' => $period->format('Y-m')])
            ->assertExitCode(0);

        $this->assertDatabaseHas('platform_invoices', [
            'tenant_id' => $tenant->id, 'plan_id' => 'growth',
            'metered_usage_cents' => 20000, 'included_usage_cents' => 15000,
            'overage_cents' => 5750, 'platform_fee_cents' => 49900, 'total_cents' => 55650,
            'status' => 'open',
        ]);

        $invoice = PlatformInvoice::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(2, $invoice->lines()->count());
    }

    public function test_no_overage_line_when_under_allowance(): void
    {
        $tenant = $this->makeTenant();
        TenantSubscription::create(['tenant_id' => $tenant->id, 'plan_id' => 'growth', 'status' => 'active']);

        $period = now()->subMonthNoOverflow();
        $this->seedUsage($tenant->id, $period->copy()->startOfMonth()->addDays(3)->toDateString(), 'llm', 40.00);

        $this->artisan('spidernet:billing:generate-invoices', ['--period' => $period->format('Y-m')])
            ->assertExitCode(0);

        $this->assertDatabaseHas('platform_invoices', [
            'tenant_id' => $tenant->id, 'overage_cents' => 0, 'total_cents' => 49900,
        ]);
        $invoice = PlatformInvoice::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(1, $invoice->lines()->count());
    }

    public function test_idempotent_regeneration_same_period(): void
    {
        $tenant = $this->makeTenant();
        TenantSubscription::create(['tenant_id' => $tenant->id, 'plan_id' => 'growth', 'status' => 'active']);
        $period = now()->subMonthNoOverflow();
        $this->seedUsage($tenant->id, $period->copy()->startOfMonth()->addDays(2)->toDateString(), 'llm', 200.00);

        $args = ['--period' => $period->format('Y-m')];
        $this->artisan('spidernet:billing:generate-invoices', $args)->assertExitCode(0);
        $this->artisan('spidernet:billing:generate-invoices', $args)->assertExitCode(0);

        // One invoice per (tenant, period), and lines not duplicated on re-run.
        $this->assertSame(1, PlatformInvoice::where('tenant_id', $tenant->id)->count());
        $invoice = PlatformInvoice::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(2, $invoice->lines()->count());
    }
}
