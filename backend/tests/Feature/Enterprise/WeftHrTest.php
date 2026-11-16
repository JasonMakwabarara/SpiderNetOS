<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\ClockEvent;
use App\Models\Employee;
use App\Models\LedgerEntry;
use App\Models\PayRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WeftHrTest extends TestCase
{
    use RefreshDatabase;

    public function test_jetson_sync_is_idempotent_and_keeps_punch_time(): void
    {
        Http::fake();
        $tenant = $this->tenant('Alpha');
        $other = $this->tenant('Beta');
        $admin = $this->user($tenant, 'admin');
        $otherAdmin = $this->user($other, 'admin');

        $this->actingAs($admin, 'sanctum');
        $created = $this->postJson('/api/enterprise/attendance/sites', ['name' => 'Harare gate'])->assertCreated();
        $siteId = $created->json('data.id');
        $secret = $created->json('secret');
        $this->assertNotEmpty($secret);

        $punchAt = '2026-10-20T06:00:00Z';
        $payload = [
            'caught_up' => true,
            'people' => [[
                'jetson_person_id' => 'pi-person-1',
                'first_name' => 'Chipo',
                'surname' => 'Ncube',
            ]],
            'devices' => [[
                'external_device_id' => 'phone-1',
                'jetson_person_id' => 'pi-person-1',
            ]],
            'clock_events' => [[
                'external_event_id' => 'punch-1',
                'external_device_id' => 'phone-1',
                'type' => 'in',
                'recorded_at' => $punchAt,
            ]],
        ];

        $first = $this->withHeaders($this->jetson($siteId, $secret))
            ->postJson('/api/enterprise/attendance/sync', $payload)
            ->assertOk();
        $number = $first->json('data.accepted.people.0.employee_number');
        $this->assertNotEmpty($number);
        $this->assertNotNull($first->json('data.last_synced_at'));

        $this->withHeaders($this->jetson($siteId, $secret))
            ->postJson('/api/enterprise/attendance/sync', $payload)
            ->assertOk()
            ->assertJsonPath('data.accepted.people.0.employee_number', $number);

        $this->assertSame(1, Employee::query()->where('tenant_id', $tenant->id)->where('jetson_person_id', 'pi-person-1')->count());
        $event = ClockEvent::query()->where('external_event_id', 'punch-1')->firstOrFail();
        $this->assertSame(1, ClockEvent::query()->where('external_event_id', 'punch-1')->count());
        $this->assertTrue($event->recorded_at->lt(now()));
        $this->assertSame(
            \Carbon\Carbon::parse($punchAt)->getTimestamp(),
            $event->recorded_at->getTimestamp(),
        );

        $deviceId = $first->json('data.register.devices.0.id')
            ?? \App\Models\AttendanceDevice::query()->where('external_device_id', 'phone-1')->value('id');
        $this->postJson('/api/enterprise/attendance/devices/'.$deviceId.'/revoke')->assertOk();
        $this->withHeaders($this->jetson($siteId, $secret))
            ->postJson('/api/enterprise/attendance/sync', [
                'caught_up' => true,
                'clock_events' => [[
                    'external_event_id' => 'punch-2',
                    'external_device_id' => 'phone-1',
                    'type' => 'out',
                    'recorded_at' => '2026-10-20T16:00:00Z',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.rejected.0.code', 'device_inactive');
        $this->assertNull(ClockEvent::query()->where('external_event_id', 'punch-2')->first());

        $this->actingAs($otherAdmin, 'sanctum');
        $this->getJson('/api/enterprise/employees?status=all')->assertOk()->assertJsonMissing(['employee_number' => $number]);
        $this->withHeaders($this->jetson($siteId, 'wrong-secret'))
            ->postJson('/api/enterprise/attendance/sync', $payload)
            ->assertUnauthorized();
    }

    public function test_hours_leave_and_payroll_journal_balance(): void
    {
        Http::fake();
        $tenant = $this->tenant('Alpha');
        $tenant->settings = ['hr' => ['daily_minutes' => 60]];
        $tenant->save();
        $other = $this->tenant('Beta');
        $admin = $this->user($tenant, 'admin');
        $member = $this->user($tenant, 'member');
        $otherAdmin = $this->user($other, 'admin');

        $this->actingAs($admin, 'sanctum');
        $monthly = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Tendai',
            'surname' => 'Moyo',
            'position_title' => 'Accountant',
        ])->assertCreated()->json('data');
        $this->patchJson('/api/enterprise/employees/'.$monthly['id'], [
            'employment_type' => 'permanent',
            'pay_basis' => 'monthly',
            'pay_rate' => 1000,
            'pay_currency' => 'USD',
            'national_id' => '63-111111-A-11',
            'nssa_number' => 'NSSA-1',
            'user_id' => $member->id,
        ])->assertOk()->assertJsonPath('data.pay_basis', 'monthly');

        $hourly = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Rudo',
            'surname' => 'Dube',
            'position_title' => 'Casual hand',
        ])->assertCreated()->json('data');
        $this->patchJson('/api/enterprise/employees/'.$hourly['id'], [
            'employment_type' => 'casual',
            'pay_basis' => 'hourly',
            'pay_rate' => 10,
            'pay_currency' => 'USD',
            'reports_to' => $monthly['id'],
        ])->assertOk();

        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $hourly['id'],
            'type' => 'in',
            'recorded_at' => '2026-10-21 09:00:00',
        ])->assertCreated();
        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $hourly['id'],
            'type' => 'out',
            'recorded_at' => '2026-10-21 11:00:00',
        ])->assertCreated();
        $open = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Open',
            'surname' => 'Shift',
            'position_title' => 'Guard',
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $open['id'],
            'type' => 'in',
            'recorded_at' => '2026-10-21 08:00:00',
        ])->assertCreated();

        $report = $this->getJson('/api/enterprise/attendance/report?from=2026-10-21&to=2026-10-21')->assertOk()->json('data');
        $hourlyRow = collect($report['totals'])->firstWhere('employee_id', $hourly['id']);
        $this->assertSame(120, $hourlyRow['payable_minutes']);
        $this->assertSame(60, $hourlyRow['overtime_minutes']);
        $openRow = collect($report['totals'])->firstWhere('employee_id', $open['id']);
        $this->assertGreaterThanOrEqual(1, $openRow['missing_out']);

        $unpaid = collect($this->getJson('/api/enterprise/leave/types')->assertOk()->json('data'))->firstWhere('code', 'unpaid');
        $leave = $this->postJson('/api/enterprise/leave/requests', [
            'employee_id' => $monthly['id'],
            'leave_type_id' => $unpaid['id'],
            'starts_on' => '2026-10-21',
            'ends_on' => '2026-10-21',
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/leave/requests/'.$leave['id'].'/resolve', ['approved' => true])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/enterprise/attendance/sites', ['name' => 'Offline gate'])->assertCreated();

        $this->postJson('/api/enterprise/payroll/rules', [
            'name' => 'Sample',
            'currency' => 'USD',
            'effective_from' => '2026-01-01',
            'paye_bands' => [['up_to' => null, 'rate' => 0.1]],
            'aids_levy_rate' => 0,
            'nssa_employee_rate' => 0,
            'nssa_employer_rate' => 0,
            'nssa_ceiling' => 0,
            'nec_rate' => 0,
            'union_dues' => 0,
        ])->assertCreated();

        $run = $this->postJson('/api/enterprise/payroll/runs', [
            'period_start' => '2026-10-19',
            'period_end' => '2026-10-23',
            'currency' => 'USD',
        ])->assertCreated()->json('data');
        $this->assertNotEmpty($run['unsynced_sites']);

        $calculated = $this->postJson('/api/enterprise/payroll/runs/'.$run['id'].'/calculate')->assertOk()->json('data');
        $slips = collect($calculated['payslips'])->keyBy('employee_id');
        $this->assertSame('800.00', $slips[$monthly['id']]['gross']);
        $this->assertSame('720.00', $slips[$monthly['id']]['net']);
        $this->assertSame('25.00', $slips[$hourly['id']]['gross']);
        $this->assertSame('22.50', $slips[$hourly['id']]['net']);

        $this->postJson('/api/enterprise/payroll/runs/'.$run['id'].'/submit')->assertOk();
        $this->postJson('/api/enterprise/payroll/runs/'.$run['id'].'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $posted = $this->postJson('/api/enterprise/payroll/runs/'.$run['id'].'/post')->assertOk()->json('data');
        $this->assertSame('posted', $posted['status']);

        $entries = LedgerEntry::query()->where('reference_id', $run['id'])->get();
        $debit = 0;
        $credit = 0;
        foreach ($entries as $entry) {
            $cents = (int) round(((float) $entry->amount) * 100);
            if ($entry->side === 'debit') {
                $debit += $cents;
            } else {
                $credit += $cents;
            }
        }
        $this->assertSame($debit, $credit);
        $this->assertSame(82500, $debit);

        $this->actingAs($member, 'sanctum');
        $this->getJson('/api/enterprise/weft/me')->assertOk()->assertJsonPath('data.employee.id', $monthly['id']);
        $this->postJson('/api/enterprise/payroll/runs', [
            'period_start' => '2026-10-19',
            'period_end' => '2026-10-23',
            'currency' => 'USD',
        ])->assertForbidden();

        $this->actingAs($otherAdmin, 'sanctum');
        $this->getJson('/api/enterprise/payroll/runs/'.$run['id'])->assertNotFound();
        $this->assertNotNull(PayRun::find($run['id']));
    }

    private function jetson(string $siteId, string $secret): array
    {
        return [
            'X-Jetson-Site' => $siteId,
            'X-Jetson-Secret' => $secret,
        ];
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
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }
}
