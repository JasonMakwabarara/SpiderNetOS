<?php

declare(strict_types=1);

namespace Tests\Unit\Spend;

use App\Models\PaymentInstruction;
use App\Models\Tenant;
use App\Services\Spend\Rails\DodoPaymentsRailAdapter;
use App\Services\Spend\Rails\PaymentRailManager;
use App\Services\Spend\Rails\RecordOnlyRail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentRailManagerTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Rail Tenant',
            'slug' => 'rail-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function makeInstruction(Tenant $tenant): PaymentInstruction
    {
        return PaymentInstruction::create([
            'tenant_id' => $tenant->id,
            'rail' => 'record_only',
            'status' => 'scheduled',
            'amount' => '100.00',
            'currency' => 'USD',
            'idempotency_key' => 'test-'.Str::uuid(),
        ]);
    }

    public function test_defaults_to_record_only_rail(): void
    {
        $tenant = $this->createTenant();

        $rail = app(PaymentRailManager::class)->railFor($tenant->id);

        $this->assertInstanceOf(RecordOnlyRail::class, $rail);
        $this->assertSame('record_only', $rail->name());
        $this->assertTrue($rail->supportsCurrency('USD'));
        $this->assertTrue($rail->supportsCurrency('ZAR')); // record-only supports everything
    }

    public function test_record_only_disbursement_settles_with_synthetic_reference(): void
    {
        $tenant = $this->createTenant();
        $instruction = $this->makeInstruction($tenant);

        $result = (new RecordOnlyRail)->createDisbursement($instruction);

        $this->assertSame('settled', $result['status']);
        $this->assertStringStartsWith('manual-', $result['external_reference']);

        $cancelled = (new RecordOnlyRail)->cancelDisbursement($result['external_reference']);
        $this->assertSame('cancelled', $cancelled['status']);
    }

    public function test_active_dodo_integration_selects_dodo_rail(): void
    {
        $tenant = $this->createTenant();

        DB::table('tenant_integrations')->insert([
            'tenant_id' => $tenant->id,
            'provider' => 'dodo_payments',
            'type' => 'payments',
            'credentials_ref' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rail = app(PaymentRailManager::class)->railFor($tenant->id);

        $this->assertInstanceOf(DodoPaymentsRailAdapter::class, $rail);
        $this->assertSame('dodo_payments', $rail->name());
    }

    public function test_inactive_dodo_integration_falls_back_to_record_only(): void
    {
        $tenant = $this->createTenant();

        DB::table('tenant_integrations')->insert([
            'tenant_id' => $tenant->id,
            'provider' => 'dodo_payments',
            'type' => 'payments',
            'credentials_ref' => null,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertInstanceOf(
            RecordOnlyRail::class,
            app(PaymentRailManager::class)->railFor($tenant->id)
        );
    }

    public function test_dodo_stub_throws_on_disbursement_and_limits_currencies(): void
    {
        $tenant = $this->createTenant();
        $instruction = $this->makeInstruction($tenant);

        $rail = new DodoPaymentsRailAdapter(['api_key' => 'test_key']);

        $this->assertTrue($rail->supportsCurrency('USD'));
        $this->assertTrue($rail->supportsCurrency('eur'));
        $this->assertTrue($rail->supportsCurrency('GBP'));
        $this->assertFalse($rail->supportsCurrency('ZAR'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dodo disbursements not yet enabled');

        $rail->createDisbursement($instruction);
    }
}
