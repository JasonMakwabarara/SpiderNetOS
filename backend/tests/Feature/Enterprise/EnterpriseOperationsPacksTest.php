<?php

declare(strict_types=1);

namespace Tests\Feature\Enterprise;

use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EnterpriseOperationsPacksTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name = 'Tenant'): Tenant
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

    private function user(Tenant $tenant): User
    {
        return User::create([
            'name' => 'Operator',
            'email' => Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);
    }

    public function test_enterprise_operations_scaffold(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $tenant = $this->tenant('Alpha');
        $other = $this->tenant('Beta');
        $user = $this->user($tenant);
        $otherUser = $this->user($other);
        $this->actingAs($user, 'sanctum');

        $department = $this->postJson('/api/enterprise/departments', ['name' => 'Stores'])->assertCreated()->json('data');
        $employee = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Amina',
            'surname' => 'Ncube',
            'position_title' => 'Storekeeper',
            'department_id' => $department['id'],
        ])->assertCreated()->json('data');
        $this->assertSame('EMP-0001', $employee['employee_number']);
        $this->assertSame('Amina Ncube', $employee['name']);

        $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Duplicate',
            'surname' => 'Number',
            'position_title' => 'Storekeeper',
            'employee_number' => 'E-1',
        ])->assertStatus(422);

        $this->actingAs($otherUser, 'sanctum');
        $this->getJson('/api/enterprise/employees')->assertOk()->assertJsonMissing(['id' => $employee['id']]);
        $this->getJson('/api/enterprise/employees/'.$employee['id'])->assertNotFound();
        $foreignEmployee = $this->postJson('/api/enterprise/employees', [
            'first_name' => 'Other',
            'surname' => 'Person',
            'position_title' => 'Driver',
        ])->assertCreated()->json('data');
        $this->actingAs($user, 'sanctum');

        $created = DB::table('event_log')->where('event_type', 'enterprise.employee.created')->orderBy('sequence_num')->first();
        $payload = json_decode((string) $created->payload, true);
        $this->assertArrayNotHasKey('name', $payload);
        $this->assertArrayNotHasKey('job_description', $payload);
        $this->assertSame($employee['id'], $payload['employee_id']);
        $this->assertSame($user->id, $payload['actor_id']);

        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $employee['id'],
            'type' => 'in',
        ])->assertCreated();
        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $employee['id'],
            'type' => 'in',
        ])->assertCreated();
        $this->postJson('/api/enterprise/clock-events', [
            'employee_id' => $foreignEmployee['id'],
            'type' => 'out',
        ])->assertNotFound();

        $requisition = $this->postJson('/api/enterprise/requisitions', [
            'title' => 'Workshop supplies',
            'employee_id' => $employee['id'],
            'lines' => [['description' => 'Gloves', 'quantity' => 2, 'unit_price' => 10]],
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('REQ-', $requisition['requisition_number']);

        $this->postJson('/api/enterprise/requisitions/'.$requisition['id'].'/submit')->assertOk()
            ->assertJsonPath('data.status', 'submitted');
        $this->postJson('/api/enterprise/requisitions/'.$requisition['id'].'/submit')->assertStatus(409);
        $this->assertSame(1, DB::table('approvals')->where('resource_id', $requisition['id'])->count());

        $approvalId = DB::table('approvals')->where('resource_id', $requisition['id'])->value('id');
        $this->actingAs($otherUser, 'sanctum');
        $this->postJson('/api/approvals/'.$approvalId.'/approve', ['reason' => 'no'])->assertNotFound();
        $this->assertSame('submitted', Requisition::find($requisition['id'])->status);
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/approvals/'.$approvalId.'/approve', ['reason' => 'ok'])->assertOk();
        $this->assertSame('approved', Requisition::find($requisition['id'])->status);

        $rejected = $this->postJson('/api/enterprise/requisitions', [
            'title' => 'Rejected request',
            'lines' => [['description' => 'Paper', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/requisitions/'.$rejected['id'].'/submit')->assertOk();
        Requisition::where('id', $rejected['id'])->update(['status' => 'draft']);
        $rejectedApproval = DB::table('approvals')->where('resource_id', $rejected['id'])->value('id');
        app(ApprovalEngine::class)->resolveApproval((string) $rejectedApproval, (string) $user->id, false, 'no');
        $this->assertSame('rejected', DB::table('approvals')->where('id', $rejectedApproval)->value('status'));
        $this->assertSame('draft', Requisition::find($rejected['id'])->status);
        $owed = DB::table('approval_actions')->where('approval_id', $rejectedApproval)->first();
        $this->assertSame('pending', $owed->status);
        $this->assertStringContainsString('not awaiting approval', (string) $owed->last_error);

        $vendor = $this->postJson('/api/enterprise/vendors', ['name' => 'BuildCo'])->assertCreated()->json('data');
        $order = $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'requisition_id' => $requisition['id'],
            'currency' => 'USD',
            'amount' => 20,
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('PO-', $order['po_number']);
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/issue')->assertOk()
            ->assertJsonPath('data.status', 'issued');
        $this->postJson('/api/enterprise/purchase-orders/'.$order['id'].'/issue')->assertStatus(409);

        $foreignReq = $this->actingAs($otherUser, 'sanctum')->postJson('/api/enterprise/requisitions', [
            'title' => 'Foreign',
            'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertCreated()->json('data');
        $this->actingAs($user, 'sanctum');
        $this->postJson('/api/enterprise/purchase-orders', [
            'vendor_id' => $vendor['id'],
            'requisition_id' => $foreignReq['id'],
            'currency' => 'USD',
            'amount' => 5,
        ])->assertNotFound();

        $asset = $this->postJson('/api/enterprise/assets', ['tag' => 'VAN-1', 'name' => 'Van'])->assertCreated()->json('data');
        $this->postJson('/api/enterprise/assets', ['tag' => 'VAN-1', 'name' => 'Again'])->assertStatus(409);
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/assign', [
            'employee_id' => $foreignEmployee['id'],
        ])->assertNotFound();
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/assign', [
            'employee_id' => $employee['id'],
        ])->assertOk()->assertJsonPath('data.status', 'assigned');
        $this->postJson('/api/enterprise/assets/'.$asset['id'].'/return')->assertOk()
            ->assertJsonPath('data.status', 'available');

        $book = $this->postJson('/api/enterprise/cashbooks', [
            'name' => 'USD Cash',
            'currency' => 'usd',
        ])->assertCreated()->json('data');
        $this->assertSame('USD', $book['currency']);
        $this->postJson('/api/enterprise/cashbooks/'.$book['id'].'/movements', [
            'type' => 'receipt',
            'amount' => 0,
            'currency' => 'USD',
            'movement_date' => now()->toDateString(),
        ])->assertStatus(422);
        $this->postJson('/api/enterprise/cashbooks/'.$book['id'].'/movements', [
            'type' => 'payment',
            'amount' => 10,
            'currency' => 'ZWG',
            'movement_date' => now()->toDateString(),
        ])->assertStatus(409);
        $this->postJson('/api/enterprise/cashbooks/'.$book['id'].'/movements', [
            'type' => 'receipt',
            'amount' => 25,
            'currency' => 'USD',
            'movement_date' => now()->toDateString(),
        ])->assertCreated();
    }
}
