<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Jobs\ExtractSpendDocumentJob;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\Bill;
use App\Models\PaymentInstruction;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BillApiTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Bill Tenant',
            'slug' => 'bill-'.Str::lower(Str::random(8)),
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

    private function createVendor(Tenant $tenant): Vendor
    {
        return Vendor::create([
            'tenant_id' => $tenant->id,
            'name' => 'Vendor '.Str::lower(Str::random(6)),
        ]);
    }

    /** admin one-step chain for bill submissions over 5000. */
    private function createBillPolicy(Tenant $tenant): ApprovalPolicy
    {
        $policy = ApprovalPolicy::create([
            'tenant_id' => $tenant->id,
            'resource_type' => 'bill',
            'action' => 'submit',
            'name' => 'High-value bills',
            'enabled' => true,
            'priority' => 10,
            'conditions' => ['min_amount' => '5000.00'],
        ]);

        ApprovalPolicyStep::create([
            'approval_policy_id' => $policy->id,
            'step_order' => 1,
            'approver_type' => 'role',
            'approver_role' => 'admin',
        ]);

        return $policy->fresh('steps');
    }

    private function createDraftBill(User $user, array $overrides = [], ?array $lines = null): array
    {
        $lines = $lines ?? [[
            'description' => 'Office chairs',
            'quantity' => '2',
            'unit_price' => '100.00',
            'tax_rate' => '10',
        ]];

        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/bills', array_merge([
                'due_date' => now()->addDays(14)->toDateString(),
                'lines' => $lines,
            ], $overrides))
            ->assertCreated()
            ->json('data');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/financial/bills')->assertStatus(401);
    }

    public function test_creates_draft_bill_with_lines_and_totals(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $vendor = $this->createVendor($tenant);

        $bill = $this->createDraftBill($user, ['vendor_id' => $vendor->id], [
            ['description' => 'Chairs', 'quantity' => '2', 'unit_price' => '100.00', 'tax_rate' => '10'],
            ['description' => 'Delivery', 'unit_price' => '50.00'],
        ]);

        $this->assertSame('draft', $bill['status']);
        $this->assertStringStartsWith('BILL-', $bill['bill_number']);
        $this->assertSame('250.0000', (string) $bill['subtotal']);
        $this->assertSame('20.0000', (string) $bill['tax_amount']);
        $this->assertSame('270.0000', (string) $bill['total_amount']);
        $this->assertCount(2, $bill['line_items']);

        $this->assertDatabaseHas('bills', ['id' => $bill['id'], 'status' => 'draft', 'vendor_id' => $vendor->id]);
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.created']);
    }

    public function test_full_state_machine_without_policy(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');

        $bill = $this->createDraftBill($member);

        // draft -> submit (no policy) -> approved
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.approved']);

        // schedule is admin-gated
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/schedule', ['date' => now()->toDateString()])
            ->assertForbidden();

        // approved -> scheduled (+ payment instruction on the record_only rail)
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/schedule', ['date' => now()->toDateString()])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $instruction = PaymentInstruction::where('bill_id', $bill['id'])->firstOrFail();
        $this->assertSame('record_only', $instruction->rail);
        $this->assertSame('scheduled', $instruction->status);
        $this->assertSame('bill-'.$bill['id'], $instruction->idempotency_key);

        // mark-paid without fresh step-up -> 428
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/mark-paid', ['bank_reference' => 'BANK-1'])
            ->assertStatus(428)
            ->assertJsonPath('error', 'step_up_required');
        $this->assertSame('scheduled', Bill::find($bill['id'])->status);

        // with fresh step-up -> paid
        $admin->update(['step_up_at' => now()]);

        $paid = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/mark-paid', [
                'bank_reference' => 'BANK-REF-77',
                'method' => 'bank_transfer',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('paid', $paid['status']);
        $this->assertNotNull($paid['paid_at']);
        $this->assertNotNull($paid['payment_id']);

        $this->assertDatabaseHas('payments', [
            'id' => $paid['payment_id'],
            'tenant_id' => $tenant->id,
            'bill_id' => $bill['id'],
            'type' => 'sent',
            'status' => 'completed',
            'provider_reference' => 'BANK-REF-77',
        ]);
        $this->assertDatabaseHas('payment_instructions', [
            'id' => $instruction->id,
            'status' => 'settled',
            'external_reference' => 'BANK-REF-77',
        ]);
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.payment_recorded']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.paid']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'payment.sent']);
    }

    public function test_submit_with_policy_awaits_approval_then_chain_resolution_approves(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $this->createBillPolicy($tenant);

        $bill = $this->createDraftBill($member, [], [[
            'description' => 'Annual hosting', 'unit_price' => '9000.00',
        ]]);

        $data = $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/submit')
            ->assertOk()
            ->json('data');

        $this->assertSame('awaiting_approval', $data['status']);
        $this->assertNotNull($data['approval_id']);
        $this->assertDatabaseHas('approvals', [
            'id' => $data['approval_id'],
            'resource_type' => 'bill',
            'resource_id' => $bill['id'],
            'status' => 'pending',
        ]);

        app(ApprovalEngine::class)->resolveStep($data['approval_id'], $admin->id, true, 'looks right');

        $fresh = Bill::find($bill['id']);
        $this->assertSame('approved', $fresh->status);
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.approved']);
    }

    public function test_chain_rejection_voids_bill_with_reason(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $this->createBillPolicy($tenant);

        $bill = $this->createDraftBill($member, [], [[
            'description' => 'Gold-plated server rack', 'unit_price' => '8000.00',
        ]]);

        $data = $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/submit')
            ->assertOk()
            ->json('data');

        app(ApprovalEngine::class)->resolveStep($data['approval_id'], $admin->id, false, 'not in budget');

        $fresh = Bill::find($bill['id']);
        $this->assertSame('void', $fresh->status);
        $this->assertNotNull($fresh->void_at);
        $this->assertSame('not in budget', $fresh->metadata['rejection_reason'] ?? null);
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.rejected']);
    }

    public function test_bill_number_unique_per_tenant_but_shared_across_tenants(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userA = $this->createUser($tenantA);
        $userB = $this->createUser($tenantB);

        $first = $this->createDraftBill($userA);
        $second = $this->createDraftBill($userA);
        $other = $this->createDraftBill($userB);

        // Same tenant: numbers advance.
        $this->assertNotSame($first['bill_number'], $second['bill_number']);

        // Different tenants share sequence values without colliding.
        $this->assertSame($first['bill_number'], $other['bill_number']);
        $this->assertSame(2, Bill::where('bill_number', $first['bill_number'])->count());
    }

    public function test_draft_only_update_guard_and_void_guards(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $admin->update(['step_up_at' => now()]);

        $bill = $this->createDraftBill($member);

        // mark-paid on a draft -> 409 (only approved|scheduled)
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/mark-paid')
            ->assertStatus(409);

        // schedule on a draft -> 409 (only approved)
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/schedule', ['date' => now()->toDateString()])
            ->assertStatus(409);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // update after leaving draft -> 409
        $this->actingAs($member, 'sanctum')
            ->putJson('/api/financial/bills/'.$bill['id'], ['notes' => 'too late'])
            ->assertStatus(409);

        // void is admin-gated
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/void')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/void', ['reason' => 'duplicate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'void');
        $this->assertDatabaseHas('event_log', ['event_type' => 'bill.voided']);

        // voiding twice -> 409
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$bill['id'].'/void')
            ->assertStatus(409);

        // paid bills cannot be voided
        $paidBill = $this->createDraftBill($member);
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$paidBill['id'].'/submit')
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$paidBill['id'].'/mark-paid', ['bank_reference' => 'REF'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$paidBill['id'].'/void')
            ->assertStatus(409);
    }

    public function test_upload_creates_draft_bill_with_spend_document_and_queues_extraction(): void
    {
        Storage::fake('local');
        Bus::fake([ExtractSpendDocumentJob::class]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $bill = $this->actingAs($user, 'sanctum')
            ->post(
                '/api/financial/bills/upload',
                ['file' => UploadedFile::fake()->image('vendor-invoice.jpg', 800, 600)],
                ['Accept' => 'application/json'],
            )
            ->assertCreated()
            ->json('data');

        $this->assertSame('draft', $bill['status']);
        $this->assertSame('upload', $bill['source']);
        $this->assertStringStartsWith('BILL-', $bill['bill_number']);
        $this->assertCount(1, $bill['documents']);

        $document = $bill['documents'][0];
        $this->assertSame('bill', $document['kind']);
        $this->assertSame('uploaded', $document['status']);
        $this->assertSame('vendor-invoice.jpg', $document['original_filename']);

        $this->assertDatabaseHas('spend_documents', [
            'id' => $document['id'],
            'tenant_id' => $tenant->id,
            'kind' => 'bill',
            'attachable_type' => Bill::class,
            'attachable_id' => $bill['id'],
            'uploaded_by' => $user->id,
        ]);

        Storage::disk('local')->assertExists($document['path']);
        $this->assertStringStartsWith("spend/{$tenant->id}/bills/", $document['path']);

        Bus::assertDispatched(
            ExtractSpendDocumentJob::class,
            fn (ExtractSpendDocumentJob $job) => $job->documentId === $document['id']
        );
    }

    public function test_show_payload_includes_typed_confirm_and_payment_block(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $admin->update(['step_up_at' => now()]);

        $small = $this->createDraftBill($member, [], [['description' => 'Pens', 'unit_price' => '12.00']]);
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/financial/bills/'.$small['id'])
            ->assertOk()
            ->assertJsonPath('data.requires_typed_confirm', false);

        $large = $this->createDraftBill($member, [], [['description' => 'Server', 'unit_price' => '1500.00']]);
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/bills/'.$large['id'].'/submit')
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$large['id'].'/schedule', ['date' => now()->addDays(3)->toDateString()])
            ->assertOk();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/bills/'.$large['id'].'/mark-paid', [
                'bank_reference' => 'WIRE-42',
                'method' => 'wire',
            ])
            ->assertOk();

        $show = $this->actingAs($member, 'sanctum')
            ->getJson('/api/financial/bills/'.$large['id'])
            ->assertOk()
            ->json('data');

        $this->assertTrue($show['requires_typed_confirm']);
        $this->assertArrayHasKey('approval_id', $show);
        $this->assertSame('wire', $show['payment']['method']);
        $this->assertSame(now()->addDays(3)->toDateString(), $show['payment']['scheduled_for']);
        $this->assertSame(now()->toDateString(), $show['payment']['paid_at']);
        $this->assertSame('WIRE-42', $show['payment']['bank_reference']);
    }

    public function test_aging_buckets_with_travel(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        // Due 10 days out: starts in current, drifts through the buckets as
        // time travels forward.
        $bill = $this->createDraftBill($user, ['due_date' => now()->addDays(10)->toDateString()], [[
            'description' => 'Aging probe', 'unit_price' => '100.00',
        ]]);

        $aging = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/bills/aging')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $aging['current']['count']);
        $this->assertEquals(100, $aging['current']['total']);

        // +30 days => 20 days overdue => 1_30 bucket.
        $this->travel(30)->days();
        $aging = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/bills/aging')->assertOk()->json('data');
        $this->assertSame(0, $aging['current']['count']);
        $this->assertSame(1, $aging['1_30']['count']);

        // +30 more => 50 days overdue => 31_60.
        $this->travel(30)->days();
        $aging = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/bills/aging')->assertOk()->json('data');
        $this->assertSame(1, $aging['31_60']['count']);

        // +30 more => 80 days overdue => 61_90.
        $this->travel(30)->days();
        $aging = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/bills/aging')->assertOk()->json('data');
        $this->assertSame(1, $aging['61_90']['count']);

        // +30 more => 110 days overdue => 90_plus.
        $this->travel(30)->days();
        $aging = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/bills/aging')->assertOk()->json('data');
        $this->assertSame(1, $aging['90_plus']['count']);

        $this->travelBack();
    }

    public function test_summary_and_due_soon_routes(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $this->createDraftBill($user, ['due_date' => now()->addDays(3)->toDateString()]);
        $this->createDraftBill($user, ['due_date' => now()->addDays(60)->toDateString()]);

        $summary = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/bills/summary')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $summary['draft']['count']);
        $this->assertArrayHasKey('paid', $summary);

        $dueSoon = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/bills/due-soon')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $dueSoon);
    }

    public function test_cross_tenant_isolation(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userA = $this->createUser($tenantA);
        $userB = $this->createUser($tenantB);

        $bill = $this->createDraftBill($userA);

        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/financial/bills/'.$bill['id'])
            ->assertNotFound();

        $list = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/financial/bills')
            ->assertOk()
            ->json('data');
        $this->assertSame(0, $list['total']);
    }
}
