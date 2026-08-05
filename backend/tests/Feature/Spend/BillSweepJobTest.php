<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Jobs\GenerateRecurringBillsJob;
use App\Jobs\SweepScheduledBillPaymentsJob;
use App\Models\Bill;
use App\Models\RecurringBillTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Spend\BillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillSweepJobTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Sweep Tenant',
            'slug' => 'sweep-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'member'): User
    {
        return User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createScheduledBill(Tenant $tenant, string $scheduledFor): Bill
    {
        $service = app(BillService::class);

        $bill = $service->createBill($tenant->id, [
            'due_date' => now()->addDays(7)->toDateString(),
        ], [[
            'description' => 'Hosting', 'unit_price' => '120.00',
        ]]);

        // No policy configured -> submit auto-approves.
        $service->submitForApproval($bill->id, $tenant->id, $this->createUser($tenant)->id);

        return $service->schedulePayment($bill->id, $tenant->id, $scheduledFor);
    }

    public function test_sweep_notifies_scheduled_bills_due_today_without_paying(): void
    {
        $tenant = $this->createTenant();
        $this->createUser($tenant, 'admin');

        $bill = $this->createScheduledBill($tenant, now()->toDateString());

        (new SweepScheduledBillPaymentsJob)->handle(app(BillService::class));

        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.payment_due']);

        // Record-only V1: the sweep never auto-pays.
        $fresh = $bill->fresh();
        $this->assertSame('scheduled', $fresh->status);
        $this->assertNull($fresh->payment_id);
        $this->assertSame(0, DB::table('payments')->where('bill_id', $bill->id)->count());

        // Second run the same day is a no-op (one notification per day per bill).
        (new SweepScheduledBillPaymentsJob)->handle(app(BillService::class));
        $this->assertSame(
            1,
            DB::table('event_log')->where('event_type', 'bill.payment_due')->count()
        );
    }

    public function test_sweep_ignores_future_scheduled_bills(): void
    {
        $tenant = $this->createTenant();
        $this->createScheduledBill($tenant, now()->addDays(5)->toDateString());

        (new SweepScheduledBillPaymentsJob)->handle(app(BillService::class));

        $this->assertSame(0, DB::table('event_log')->where('event_type', 'bill.payment_due')->count());
    }

    public function test_recurring_template_generates_exactly_once_per_period(): void
    {
        $tenant = $this->createTenant();

        $template = RecurringBillTemplate::create([
            'tenant_id' => $tenant->id,
            'name' => 'Office rent',
            'amount' => '1500.00',
            'currency' => 'USD',
            'cadence' => 'monthly',
            'day_of_month' => 1,
            'next_run_date' => now()->toDateString(),
            'autocreate' => true,
            'enabled' => true,
        ]);

        (new GenerateRecurringBillsJob)->handle(app(BillService::class), app(\App\Services\EventStore::class));
        (new GenerateRecurringBillsJob)->handle(app(BillService::class), app(\App\Services\EventStore::class));

        $this->assertSame(1, Bill::forTenant($tenant->id)->count());

        $bill = Bill::forTenant($tenant->id)->firstOrFail();
        $this->assertSame('draft', $bill->status);
        $this->assertSame('Generated from recurring template Office rent', $bill->notes);
        $this->assertSame('1500.0000', (string) $bill->total_amount);

        $fresh = $template->fresh();
        $this->assertSame(now()->format('Y-m'), $fresh->last_period_key);
        $this->assertTrue($fresh->next_run_date->gt(now()));
    }

    public function test_disabled_and_manual_templates(): void
    {
        $tenant = $this->createTenant();

        RecurringBillTemplate::create([
            'tenant_id' => $tenant->id,
            'name' => 'Disabled sub',
            'amount' => '10.00',
            'cadence' => 'monthly',
            'next_run_date' => now()->toDateString(),
            'enabled' => false,
        ]);

        $manual = RecurringBillTemplate::create([
            'tenant_id' => $tenant->id,
            'name' => 'Manual insurance',
            'amount' => '900.00',
            'cadence' => 'monthly',
            'next_run_date' => now()->toDateString(),
            'autocreate' => false,
            'enabled' => true,
        ]);

        (new GenerateRecurringBillsJob)->handle(app(BillService::class), app(\App\Services\EventStore::class));

        // Neither template creates a bill; the manual one emits an event instead.
        $this->assertSame(0, Bill::forTenant($tenant->id)->count());
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.recurring_due']);
        $this->assertNotNull($manual->fresh()->last_period_key);
    }
}
