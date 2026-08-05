<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Jobs\GenerateRecurringBillsJob;
use App\Models\Bill;
use App\Models\RecurringBillTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventStore;
use App\Services\Spend\BillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecurringBillTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Recurring Tenant',
            'slug' => 'recur-'.Str::lower(Str::random(8)),
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

    private function runJob(): void
    {
        (new GenerateRecurringBillsJob)->handle(app(BillService::class), app(EventStore::class));
    }

    public function test_monthly_cadence_advances_with_day_clamp(): void
    {
        $this->travelTo(Carbon::parse('2026-01-31'));

        $tenant = $this->createTenant();
        $template = RecurringBillTemplate::create([
            'tenant_id' => $tenant->id,
            'name' => 'Rent',
            'amount' => '2000.00',
            'cadence' => 'monthly',
            'day_of_month' => 31,
            'next_run_date' => '2026-01-31',
            'autocreate' => true,
            'enabled' => true,
        ]);

        $this->runJob();

        $fresh = $template->fresh();
        $this->assertSame('2026-01', $fresh->last_period_key);
        // 2026 is not a leap year: day 31 clamps to Feb 28.
        $this->assertSame('2026-02-28', $fresh->next_run_date->toDateString());
        $this->assertSame(1, Bill::forTenant($tenant->id)->count());

        // Next month: Feb 28 run advances back out to Mar 31.
        $this->travelTo(Carbon::parse('2026-02-28'));
        $this->runJob();

        $fresh = $template->fresh();
        $this->assertSame('2026-02', $fresh->last_period_key);
        $this->assertSame('2026-03-31', $fresh->next_run_date->toDateString());
        $this->assertSame(2, Bill::forTenant($tenant->id)->count());

        $this->travelBack();
    }

    public function test_weekly_cadence_advances_seven_days_with_iso_period_key(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05')); // Wednesday, ISO week 32

        $tenant = $this->createTenant();
        $template = RecurringBillTemplate::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cleaning service',
            'amount' => '80.00',
            'cadence' => 'weekly',
            'next_run_date' => '2026-08-05',
            'autocreate' => true,
            'enabled' => true,
        ]);

        $this->runJob();

        $fresh = $template->fresh();
        $this->assertSame('2026-32', $fresh->last_period_key);
        $this->assertSame('2026-08-12', $fresh->next_run_date->toDateString());
        $this->assertSame(1, Bill::forTenant($tenant->id)->count());

        $this->travelBack();
    }

    public function test_period_idempotency_when_next_run_date_is_rewound(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05'));

        $tenant = $this->createTenant();
        $template = RecurringBillTemplate::create([
            'tenant_id' => $tenant->id,
            'name' => 'SaaS subscription',
            'amount' => '49.00',
            'cadence' => 'monthly',
            'day_of_month' => 5,
            'next_run_date' => '2026-08-05',
            'autocreate' => true,
            'enabled' => true,
        ]);

        $this->runJob();
        $this->assertSame(1, Bill::forTenant($tenant->id)->count());

        // Simulate an operator rewinding next_run_date into the same period.
        $template->fresh()->update(['next_run_date' => '2026-08-05']);

        $this->runJob();

        // No duplicate bill; next_run_date advanced past the period again.
        $this->assertSame(1, Bill::forTenant($tenant->id)->count());
        $fresh = $template->fresh();
        $this->assertSame('2026-08', $fresh->last_period_key);
        $this->assertSame('2026-09-05', $fresh->next_run_date->toDateString());

        $this->travelBack();
    }

    public function test_api_crud_is_admin_gated(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');

        $payload = [
            'name' => 'Rent',
            'amount' => '2000.00',
            'cadence' => 'monthly',
            'day_of_month' => 1,
            'next_run_date' => now()->addDays(3)->toDateString(),
        ];

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/recurring-bills', $payload)
            ->assertForbidden();

        $template = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/recurring-bills', $payload)
            ->assertCreated()
            ->json('data');

        // Reads are open to any tenant user.
        $list = $this->actingAs($member, 'sanctum')
            ->getJson('/api/financial/recurring-bills')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $list['total']);

        $this->actingAs($member, 'sanctum')
            ->putJson('/api/financial/recurring-bills/'.$template['id'], ['enabled' => false])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/financial/recurring-bills/'.$template['id'], ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->actingAs($member, 'sanctum')
            ->deleteJson('/api/financial/recurring-bills/'.$template['id'])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/financial/recurring-bills/'.$template['id'])
            ->assertOk();

        $this->assertDatabaseMissing('recurring_bill_templates', ['id' => $template['id']]);
    }
}
