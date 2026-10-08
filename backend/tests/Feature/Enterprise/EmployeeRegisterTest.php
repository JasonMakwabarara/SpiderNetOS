<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\Employee;
use App\Models\HrAuditEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Financial\DocumentNumberService;
use App\Support\EmployeeNameBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class EmployeeRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Employee register test requires PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_employee_register_contract(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $tenant = $this->tenant('Alpha');
        $other = $this->tenant('Beta');
        $admin = $this->user($tenant, 'admin');
        $member = $this->user($tenant, 'member');
        $otherAdmin = $this->user($other, 'admin');

        $this->actingAs($admin, 'sanctum');
        $department = $this->postJson('/api/enterprise/departments', ['name' => 'Procurement'])->assertCreated()->json('data');
        $finance = $this->postJson('/api/enterprise/departments', ['name' => 'Finance'])->assertCreated()->json('data');

        $first = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Tendai',
            'surname' => 'Moyo',
            'position_title' => 'Procurement Officer',
            'department_id' => $department['id'],
        ])->assertCreated()->json('data');
        $this->assertSame('EMP-0001', $first['employee_number']);
        $this->assertSame('Tendai Moyo', $first['name']);
        $this->assertStringContainsString('supplier sourcing', $first['job_description']);

        $second = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Rudo',
            'surname' => 'Mlambo',
            'position_title' => 'Accountant',
            'department_id' => $finance['id'],
        ])->assertCreated()->json('data');
        $this->assertSame('EMP-0002', $second['employee_number']);

        foreach (['employee_number' => 'CEO-001', 'tenant_id' => $other->id, 'id' => (string) Str::uuid(), 'name' => 'Ignored'] as $field => $value) {
            $this->postJson('/api/enterprise/employees', [
                'first_name' => 'John',
                'surname' => 'Dube',
                'position_title' => 'Driver',
                $field => $value,
            ])->assertStatus(422);
            $this->patchJson('/api/enterprise/employees/'.$first['id'], [$field => $value])->assertStatus(422);
        }

        $this->patchJson('/api/enterprise/employees/'.$first['id'], ['status' => 'inactive'])->assertStatus(422);
        $renamed = $this->patchJson('/api/enterprise/employees/'.$first['id'], [
            'first_name' => 'Tendai',
            'surname' => 'Moyo',
            'position_title' => 'Senior Procurement Officer',
        ])->assertOk()->json('data');
        $this->assertSame('Tendai Moyo', $renamed['name']);
        $this->assertSame('Senior Procurement Officer', $renamed['position_title']);
        $this->assertTrue(
            HrAuditEntry::query()->where('employee_id', $first['id'])->where('field', 'position_title')->exists()
        );

        $this->postJson('/api/enterprise/employees/'.$first['id'].'/deactivate', [
            'reason' => 'Employment ended',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->assertNotNull(Employee::find($first['id']));
        foreach (['status', 'inactive_from', 'inactive_reason'] as $field) {
            $this->assertTrue(HrAuditEntry::query()->where('employee_id', $first['id'])->where('field', $field)->where('event', 'deactivated')->exists());
        }

        $this->getJson('/api/enterprise/employees?q=Rudo')->assertOk()->assertJsonFragment(['id' => $second['id']]);
        $this->getJson('/api/enterprise/employees?q=Accountant')->assertOk()->assertJsonFragment(['id' => $second['id']]);
        $this->getJson('/api/enterprise/employees?q=Finance')->assertOk()->assertJsonFragment(['id' => $second['id']]);
        $this->getJson('/api/enterprise/employees')->assertOk()->assertJsonMissing(['id' => $first['id']]);
        $this->getJson('/api/enterprise/employees?status=all')->assertOk()->assertJsonFragment(['id' => $first['id']]);

        $this->actingAs($otherAdmin, 'sanctum');
        $this->getJson('/api/enterprise/employees?status=all')->assertOk()->assertJsonMissing(['id' => $second['id']]);
        $this->getJson('/api/enterprise/employees/'.$second['id'])->assertNotFound();
        $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Foreign',
            'surname' => 'Hire',
            'position_title' => 'Driver',
            'department_id' => $department['id'],
        ])->assertNotFound();

        $this->actingAs($admin, 'sanctum');
        $this->patchJson('/api/enterprise/employees/'.$second['id'], [
            'department_id' => (string) Str::uuid(),
        ])->assertNotFound();

        $this->actingAs($member, 'sanctum');
        foreach ([
            ['get', '/api/enterprise/employees'],
            ['get', '/api/enterprise/employees/'.$second['id']],
            ['get', '/api/enterprise/hr/settings'],
            ['get', '/api/enterprise/employees/'.$second['id'].'/audit.csv'],
        ] as [$method, $url]) {
            $this->json($method, $url)->assertForbidden();
        }
        $this->postJson('/api/enterprise/employees', [
            'first_name' => 'No',
            'surname' => 'Access',
            'position_title' => 'Driver',
        ])->assertForbidden();
        $this->patchJson('/api/enterprise/employees/'.$second['id'], ['surname' => 'Nope'])->assertForbidden();
        $this->postJson('/api/enterprise/employees/'.$second['id'].'/deactivate', [])->assertForbidden();
        $this->postJson('/api/enterprise/employees/job-description-suggestion', [
            'position_title' => 'Driver',
        ])->assertForbidden();
        $this->patchJson('/api/enterprise/hr/settings', [
            'employee_number_prefix' => 'HT-EMP',
            'employee_number_padding' => 4,
        ])->assertForbidden();

        $this->actingAs($admin, 'sanctum');
        $createdRaw = \DB::table('event_log')->where('aggregate_id', $second['id'])->where('event_type', 'enterprise.employee.created')->value('payload');
        $createdEvent = is_array($createdRaw) ? $createdRaw : json_decode((string) $createdRaw, true);
        $this->assertEqualsCanonicalizing(['employee_id', 'fields_changed', 'actor_id'], array_keys($createdEvent));
        $encoded = json_encode($createdEvent);
        $this->assertStringNotContainsString('Rudo', $encoded);
        $this->assertStringNotContainsString('Accountant', $encoded);

        $deactivatedRaw = \DB::table('event_log')->where('aggregate_id', $first['id'])->where('event_type', 'enterprise.employee.deactivated')->value('payload');
        $deactivated = is_array($deactivatedRaw) ? $deactivatedRaw : json_decode((string) $deactivatedRaw, true);
        $this->assertStringNotContainsString('Employment ended', json_encode($deactivated));

        HrAuditEntry::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $second['id'],
            'actor_user_id' => $admin->id,
            'event' => 'updated',
            'field' => 'job_description',
            'previous_value' => 'Safe',
            'new_value' => '=1+1',
            'created_at' => now(),
        ]);
        $csv = $this->get('/api/enterprise/employees/'.$second['id'].'/audit.csv')->assertOk()->getContent();
        $this->assertStringContainsString('Accountant', $csv);
        $this->assertStringContainsString("'=1+1", $csv);
        Http::assertNothingSent();

        $this->postJson('/api/enterprise/employees/job-description-suggestion', [
            'position_title' => 'Procurement Officer',
        ])->assertOk()->assertJsonPath('data.job_description', $first['job_description']);
        Http::assertNothingSent();

        $asset = $this->postJson('/api/enterprise/assets', ['tag' => 'LAP-1', 'name' => 'Laptop'])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/assign', [
            'employee_id' => $first['id'],
        ])->assertStatus(409);
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/assign', [
            'employee_id' => $second['id'],
        ])->assertOk();

        $settings = $this->patchJson('/api/enterprise/hr/settings', [
            'employee_number_prefix' => 'HT-EMP',
            'employee_number_padding' => 4,
        ])->assertOk()->json('data');
        $this->assertSame('HT-EMP-0003', $settings['next_employee_number']);
        $this->patchJson('/api/enterprise/hr/settings', [
            'employee_number_prefix' => 'HT-EMP',
            'employee_number_padding' => 4,
            'next_employee_number' => 'HT-EMP-0001',
        ])->assertStatus(422);
        $third = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'John',
            'surname' => 'Dube',
            'position_title' => 'Driver',
        ])->assertCreated()->json('data');
        $this->assertSame('HT-EMP-0003', $third['employee_number']);

        $numbers = app(DocumentNumberService::class);
        $this->assertNotSame(
            $numbers->nextSerial($tenant->id, 'employee', 'HT-EMP', 4),
            $numbers->nextSerial($tenant->id, 'employee', 'HT-EMP', 4),
        );

        $entry = HrAuditEntry::query()->where('employee_id', $second['id'])->firstOrFail();
        try {
            $entry->update(['event' => 'tampered']);
            $this->fail('Audit rows must not update.');
        } catch (LogicException) {
            $this->assertNotSame('tampered', $entry->fresh()->event);
        }

        $this->assertSame(
            ['first_name' => 'Prince', 'surname' => ''],
            EmployeeNameBackfill::split('Prince'),
        );
        $this->assertSame(
            ['first_name' => 'Mary', 'surname' => 'Jane Smith'],
            EmployeeNameBackfill::split('Mary Jane Smith'),
        );
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
