<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\Employee;
use App\Models\LedgerEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkforceDepthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Workforce depth test requires PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_closed_workforce_and_asset_depth_stays_on_the_canonical_records(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $tenant = $this->tenant('Alpha');
        $other = $this->tenant('Beta');
        $admin = $this->user($tenant, 'admin');
        $otherAdmin = $this->user($other, 'admin');
        $this->actingAs($admin, 'sanctum');

        $kept = 'Keep this job description.';
        $employee = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Tendai',
            'surname' => 'Moyo',
            'position_title' => 'Clerk',
            'job_description' => $kept,
        ])->assertCreated()->json('data');

        $position = $this->postJson('/api/enterprise/positions', ['name' => 'Stores clerk'])->assertCreated()->json('data');
        $assigned = $this->postJson('/api/enterprise/employees/'.$employee['id'].'/position', [
            'position_id' => $position['id'],
        ])->assertOk()->json('data');
        $this->assertSame('Stores clerk', $assigned['position_title']);
        $this->assertSame($kept, $assigned['job_description']);

        $this->postJson('/api/enterprise/job-description-templates', [
            'position_title' => 'Workshop lead',
            'body' => 'Custody of the workshop record.',
        ])->assertCreated();
        $this->postJson('/api/enterprise/employees/job-description-suggestion', [
            'position_title' => 'Workshop lead',
        ])->assertOk()->assertJsonPath('data.job_description', 'Custody of the workshop record.');
        $this->postJson('/api/enterprise/employees/job-description-suggestion', [
            'position_title' => 'Stores clerk',
        ])->assertOk()->assertJsonPath(
            'data.job_description',
            'Responsible for receiving goods, checking quantities against documents, storing stock, and issuing items against authorised requests.',
        );

        $shift = $this->postJson('/api/enterprise/shifts', [
            'name' => 'Day',
            'starts_at' => '08:00',
            'ends_at' => '17:00',
            'grace_minutes' => 5,
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/employees/'.$employee['id'].'/shift', [
            'shift_id' => $shift['id'],
            'effective_from' => '2026-10-08',
        ])->assertCreated();
        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $employee['id'],
            'type' => 'in',
            'recorded_at' => '2026-10-08 08:20:00',
        ])->assertCreated();
        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $employee['id'],
            'type' => 'out',
            'recorded_at' => '2026-10-08 12:00:00',
        ])->assertCreated();

        $day = $this->postJson('/api/enterprise/employees/'.$employee['id'].'/attendance-days', [
            'work_date' => '2026-10-08',
        ])->assertCreated()->json('data');
        $this->assertSame('late', $day['punctuality']);
        $this->assertSame(220, $day['minutes']);
        $this->postJson('/api/enterprise/employees/'.$employee['id'].'/attendance-days', [
            'work_date' => '2026-10-08',
        ])->assertStatus(409);

        $this->actingAs($otherAdmin, 'sanctum');
        $this->postJson('/api/enterprise/employees/'.$employee['id'].'/attendance-days', [
            'work_date' => '2026-10-09',
        ])->assertNotFound();
        $this->assertSame(1, \DB::table('attendance_days')->count());
        $this->actingAs($admin, 'sanctum');

        $closed = \DB::table('event_log')->where('aggregate_id', $day['id'])->value('payload');
        $closedPayload = is_array($closed) ? $closed : json_decode((string) $closed, true);
        $closedKeys = array_keys($closedPayload);
        sort($closedKeys);
        $this->assertSame(['attendance_day_id', 'employee_id', 'punctuality'], $closedKeys);
        $this->assertStringNotContainsString('Tendai', json_encode($closedPayload));

        $leave = $this->postJson('/api/enterprise/employees/'.$employee['id'].'/leave', [
            'leave_type' => 'annual',
            'starts_on' => '2026-10-20',
            'ends_on' => '2026-10-21',
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/leave-requests/'.$leave['id'].'/submit')->assertOk();
        $this->actingAs($otherAdmin, 'sanctum');
        $this->getJson('/api/enterprise/leave-requests/'.$leave['id'])->assertNotFound();
        $this->actingAs($admin, 'sanctum');
        $approvalId = \DB::table('approvals')->where('resource_id', $leave['id'])->value('id');
        $this->postJson('/api/approvals/'.$approvalId.'/approve', ['reason' => 'Granted'])->assertOk();
        $this->assertSame('approved', \DB::table('leave_requests')->where('id', $leave['id'])->value('status'));
        $this->assertSame('active', Employee::find($employee['id'])->status);

        $leaveEvent = \DB::table('event_log')->where('aggregate_id', $leave['id'])->where('event_type', 'enterprise.leave.approved')->value('payload');
        $leavePayload = is_array($leaveEvent) ? $leaveEvent : json_decode((string) $leaveEvent, true);
        $leaveKeys = array_keys($leavePayload);
        sort($leaveKeys);
        $this->assertSame(['employee_id', 'leave_request_id', 'status'], $leaveKeys);

        $contract = $this->postJson('/api/enterprise/employees/'.$employee['id'].'/contracts', [
            'starts_on' => '2026-10-01',
            'pay_amount' => 10,
            'currency' => 'USD',
            'pay_period' => 'hour',
        ])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^CTR-\d{6}$/', $contract['contract_number']);
        $this->postJson('/api/enterprise/contracts/'.$contract['id'].'/activate')->assertOk();
        $second = $this->postJson('/api/enterprise/employees/'.$employee['id'].'/contracts', [
            'starts_on' => '2026-10-01',
            'pay_amount' => 12,
            'currency' => 'USD',
            'pay_period' => 'hour',
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/contracts/'.$second['id'].'/activate')->assertStatus(409);

        $run = $this->postJson('/api/enterprise/payroll-runs', [
            'period_start' => '2026-10-08',
            'period_end' => '2026-10-08',
            'currency' => 'USD',
        ])->assertCreated()->json('data');
        $this->assertSame('36.6660', $run['lines'][0]['amount']);
        $posted = $this->postJson('/api/enterprise/payroll-runs/'.$run['id'].'/post')->assertCreated()->json('data');
        $this->assertSame('posted', $posted['status']);
        $entries = LedgerEntry::query()->where('reference_id', $run['id'])->get();
        $this->assertCount(2, $entries);
        $this->assertEquals(36.666, (float) $entries->first()->amount);
        $payrollEvent = \DB::table('event_log')->where('aggregate_id', $run['id'])->where('event_type', 'enterprise.payroll.posted')->value('payload');
        $payrollPayload = is_array($payrollEvent) ? $payrollEvent : json_decode((string) $payrollEvent, true);
        $payrollKeys = array_keys($payrollPayload);
        sort($payrollKeys);
        $this->assertSame(['payroll_run_id', 'status'], $payrollKeys);
        $this->assertStringNotContainsString('36.6660', json_encode($payrollPayload));

        $this->postJson('/api/enterprise/supplier-invoices/'.(string) Str::uuid().'/fiscalise', [
            'environment' => 'live',
        ])->assertStatus(409);
        Http::assertNothingSent();

        $asset = $this->postJson('/api/enterprise/assets', [
            'tag' => 'VAN-1',
            'name' => 'Van',
            'acquired_on' => '2026-01-01',
            'cost' => 1200,
            'residual_value' => 0,
            'useful_life_months' => 12,
        ])->assertCreated()->json('data');
        $entry = $this->postJson('/api/enterprise/assets/'.$asset['id'].'/depreciate', [
            'period' => '2026-10',
        ])->assertCreated()->json('data');
        $this->assertSame('100.0000', $entry['amount']);
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/depreciate', [
            'period' => '2026-10',
        ])->assertStatus(409);
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/assign', [
            'employee_id' => $employee['id'],
        ])->assertOk();
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/dispose')->assertStatus(409);
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/return')->assertOk();
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/dispose')->assertOk()
            ->assertJsonPath('data.status', 'disposed');
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/depreciate', [
            'period' => '2026-11',
        ])->assertStatus(409);

        $this->actingAs($otherAdmin, 'sanctum');
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/depreciate', [
            'period' => '2026-11',
        ])->assertNotFound();
        $this->actingAs($admin, 'sanctum');

        $depEvent = \DB::table('event_log')->where('aggregate_id', $entry['id'])->value('payload');
        $depPayload = is_array($depEvent) ? $depEvent : json_decode((string) $depEvent, true);
        $depKeys = array_keys($depPayload);
        sort($depKeys);
        $this->assertSame(['asset_id', 'depreciation_entry_id', 'status'], $depKeys);
        $this->assertSame(2, LedgerEntry::query()->where('reference_id', $entry['id'])->count());
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function user(Tenant $tenant, string $role): User
    {
        return User::create([
            'name' => 'Operator',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => 'password',
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }
}
