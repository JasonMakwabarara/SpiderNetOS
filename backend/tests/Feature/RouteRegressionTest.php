<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RouteRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Route regression test requires PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_aging_report_buckets_overdue_invoices_and_keeps_future_ones_current(): void
    {
        Carbon::setTestNow('2026-10-08 12:00:00');
        $tenant = $this->tenant();
        $this->actingAs($this->user($tenant), 'sanctum');
        $this->invoice($tenant, 'INV-A', '2026-11-07', 100);
        $this->invoice($tenant, 'INV-B', '2026-09-28', 40);
        $this->invoice($tenant, 'INV-C', '2026-06-01', 7);
        $this->invoice($tenant, 'INV-D', '2026-09-01', 999, 'paid');

        $aging = $this->getJson('/api/financial/aging-report')->assertOk()->json('data');

        $this->assertSame(1, $aging['current']['count']);
        $this->assertEquals(100, $aging['current']['amount']);
        $this->assertSame(1, $aging['1_30_days']['count']);
        $this->assertEquals(40, $aging['1_30_days']['amount']);
        $this->assertSame(1, $aging['90_plus_days']['count']);
        $this->assertSame(0, $aging['31_60_days']['count']);
        Carbon::setTestNow();
    }

    public function test_agent_sub_routes_reach_their_own_handlers(): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->user($tenant), 'sanctum');
        $unknown = (string) Str::uuid();

        $this->getJson('/api/agents/'.$unknown.'/capabilities')->assertNotFound();
        $this->getJson('/api/agents/'.$unknown.'/sessions')->assertNotFound();
    }

    /**
     * Postgres aborts the surrounding transaction on a malformed UUID, so one request per case.
     */
    #[DataProvider('malformedIdRoutes')]
    public function test_malformed_ids_are_not_found_instead_of_server_errors(string $path): void
    {
        $tenant = $this->tenant();
        $this->actingAs($this->user($tenant), 'sanctum');

        $this->getJson($path)->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedIdRoutes(): array
    {
        return [
            'agent' => ['/api/agents/not-a-uuid'],
            'employee' => ['/api/enterprise/employees/not-a-uuid'],
            'invoice' => ['/api/financial/invoices/not-a-uuid'],
            'flow' => ['/api/flows/not-a-uuid'],
        ];
    }

    private function invoice(Tenant $tenant, string $number, string $due, float $total, string $status = 'sent'): void
    {
        Invoice::create([
            'tenant_id' => $tenant->id,
            'invoice_number' => $number,
            'customer_name' => 'Customer',
            'subtotal' => $total,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => $total,
            'currency' => 'USD',
            'status' => $status,
            'issue_date' => '2026-05-01',
            'due_date' => $due,
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => 'Regression',
            'slug' => 'regression-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function user(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Operator',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }
}
