<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\ExpenseCategory;
use App\Models\ExpenseReport;
use App\Models\Reimbursement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Spend Tenant',
            'slug' => 'spend-'.Str::lower(Str::random(8)),
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

    /** member -> admin two-step chain for expense_report submissions over 5000. */
    private function createTieredPolicy(Tenant $tenant): ApprovalPolicy
    {
        $policy = ApprovalPolicy::create([
            'tenant_id' => $tenant->id,
            'resource_type' => 'expense_report',
            'action' => 'submit',
            'name' => 'High-value expenses',
            'enabled' => true,
            'priority' => 10,
            'conditions' => ['min_amount' => '5000.00'],
        ]);

        ApprovalPolicyStep::create([
            'approval_policy_id' => $policy->id,
            'step_order' => 1,
            'approver_type' => 'role',
            'approver_role' => 'member',
            'expires_after_hours' => 24,
            'escalate_to_role' => 'admin',
        ]);
        ApprovalPolicyStep::create([
            'approval_policy_id' => $policy->id,
            'step_order' => 2,
            'approver_type' => 'role',
            'approver_role' => 'admin',
        ]);

        return $policy->fresh('steps');
    }

    private function createDraftReport(User $user, array $items = []): array
    {
        $items = $items ?: [[
            'description' => 'Client lunch',
            'amount' => '45.50',
            'expense_date' => now()->toDateString(),
        ]];

        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses', [
                'title' => 'Test report',
                'items' => $items,
            ])
            ->assertCreated()
            ->json('data');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/financial/expenses')->assertStatus(401);
    }

    public function test_creates_draft_report_with_items(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $data = $this->createDraftReport($user, [
            ['description' => 'Taxi', 'amount' => '20.00', 'expense_date' => now()->toDateString()],
            ['description' => 'Lunch', 'amount' => '30.50', 'expense_date' => now()->toDateString()],
        ]);

        $this->assertSame('draft', $data['status']);
        $this->assertStringStartsWith('EXP-', $data['report_number']);
        $this->assertSame('50.5000', (string) $data['total_amount']);
        $this->assertCount(2, $data['items']);
        $this->assertDatabaseHas('expense_reports', ['id' => $data['id'], 'status' => 'draft']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'expense_report.created']);
    }

    public function test_draft_only_guard_after_submit(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $report = $this->createDraftReport($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/financial/expenses/'.$report['id'], ['title' => 'Nope'])
            ->assertStatus(409);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/items', [
                'description' => 'Late item',
                'amount' => '10.00',
                'expense_date' => now()->toDateString(),
            ])
            ->assertStatus(409);

        $itemId = $report['items'][0]['id'];
        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/financial/expenses/'.$report['id'].'/items/'.$itemId)
            ->assertStatus(409);
    }

    public function test_submit_without_policy_auto_approves_and_creates_reimbursement(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $report = $this->createDraftReport($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('reimbursements', [
            'expense_report_id' => $report['id'],
            'status' => 'pending',
            'user_id' => $user->id,
        ]);
        $reimbursement = Reimbursement::where('expense_report_id', $report['id'])->first();
        $this->assertStringStartsWith('REI-', $reimbursement->reimbursement_number);

        $this->assertDatabaseHas('event_log', ['event_type' => 'expense_report.submitted']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'expense_report.approved']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'reimbursement.created']);
    }

    public function test_submit_with_policy_awaits_approval_then_chain_resolution_approves(): void
    {
        $tenant = $this->createTenant();
        $submitter = $this->createUser($tenant, 'member');
        $peer = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $this->createTieredPolicy($tenant);

        $report = $this->createDraftReport($submitter, [[
            'description' => 'Conference sponsorship',
            'amount' => '9000.00',
            'expense_date' => now()->toDateString(),
        ]]);

        $data = $this->actingAs($submitter, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk()
            ->json('data');

        $this->assertSame('awaiting_approval', $data['status']);
        $this->assertNotNull($data['approval_id']);
        $this->assertDatabaseHas('approvals', [
            'id' => $data['approval_id'],
            'resource_type' => 'expense_report',
            'resource_id' => $report['id'],
            'status' => 'pending',
        ]);

        // No reimbursement until the chain resolves.
        $this->assertSame(0, Reimbursement::where('expense_report_id', $report['id'])->count());

        $engine = app(ApprovalEngine::class);
        $engine->resolveStep($data['approval_id'], $peer->id, true, 'step 1 ok');

        $this->assertSame('awaiting_approval', ExpenseReport::find($report['id'])->status);

        $engine->resolveStep($data['approval_id'], $admin->id, true, 'step 2 ok');

        $fresh = ExpenseReport::find($report['id']);
        $this->assertSame('approved', $fresh->status);
        $this->assertNotNull($fresh->approved_at);
        $this->assertDatabaseHas('reimbursements', [
            'expense_report_id' => $report['id'],
            'status' => 'pending',
        ]);
    }

    public function test_chain_rejection_marks_report_rejected_with_reason(): void
    {
        $tenant = $this->createTenant();
        $submitter = $this->createUser($tenant, 'member');
        $peer = $this->createUser($tenant, 'member');
        $this->createTieredPolicy($tenant);

        $report = $this->createDraftReport($submitter, [[
            'description' => 'Gold-plated stapler',
            'amount' => '8000.00',
            'expense_date' => now()->toDateString(),
        ]]);

        $data = $this->actingAs($submitter, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk()
            ->json('data');

        app(ApprovalEngine::class)->resolveStep($data['approval_id'], $peer->id, false, 'not justified');

        $fresh = ExpenseReport::find($report['id']);
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('not justified', $fresh->rejection_reason);
        $this->assertSame(0, Reimbursement::where('expense_report_id', $report['id'])->count());
        $this->assertDatabaseHas('event_log', ['event_type' => 'expense_report.rejected']);
    }

    public function test_policy_violation_flags_set_on_submit(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $category = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Meals',
            'slug' => 'meals',
            'per_expense_limit' => '50.00',
            'requires_receipt_over' => '25.00',
        ]);

        $report = $this->createDraftReport($user, [[
            'description' => 'Team dinner',
            'amount' => '120.00',
            'expense_date' => now()->toDateString(),
            'category_id' => $category->id,
        ]]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk();

        $show = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/expenses/'.$report['id'])
            ->assertOk()
            ->json('data');

        $flags = $show['line_items'][0]['policy_flags'];
        $this->assertContains('over_category_limit', $flags);
        $this->assertContains('missing_receipt', $flags);
        $this->assertGreaterThanOrEqual(1, $show['policy_violation_count']);
    }

    public function test_show_includes_requires_typed_confirm(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $small = $this->createDraftReport($user, [[
            'description' => 'Pens', 'amount' => '12.00', 'expense_date' => now()->toDateString(),
        ]]);
        $large = $this->createDraftReport($user, [[
            'description' => 'Laptop', 'amount' => '1500.00', 'expense_date' => now()->toDateString(),
        ]]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/expenses/'.$small['id'])
            ->assertOk()
            ->assertJsonPath('data.requires_typed_confirm', false);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/expenses/'.$large['id'])
            ->assertOk()
            ->assertJsonPath('data.requires_typed_confirm', true);
    }

    public function test_cross_tenant_isolation(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $userA = $this->createUser($tenantA);
        $userB = $this->createUser($tenantB);

        $report = $this->createDraftReport($userA);

        $this->actingAs($userB, 'sanctum')
            ->getJson('/api/financial/expenses/'.$report['id'])
            ->assertNotFound();

        $list = $this->actingAs($userB, 'sanctum')
            ->getJson('/api/financial/expenses?scope=team')
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $list['total']);
    }

    public function test_scope_mine_filters_to_own_reports(): void
    {
        $tenant = $this->createTenant();
        $alice = $this->createUser($tenant);
        $bob = $this->createUser($tenant);

        $this->createDraftReport($alice);
        $this->createDraftReport($bob);

        $mine = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/financial/expenses?scope=mine')
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $mine['total']);

        $team = $this->actingAs($alice, 'sanctum')
            ->getJson('/api/financial/expenses?scope=team')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $team['total']);
    }

    public function test_void_report(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $report = $this->createDraftReport($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/void')
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        // Voided reports cannot be voided twice.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/void')
            ->assertStatus(409);
    }

    public function test_summary_route_reachable(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $report = $this->createDraftReport($user);

        $summary = $this->actingAs($user, 'sanctum')
            ->getJson('/api/financial/expenses/summary')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, $summary['draft']['count']);
        $this->assertArrayHasKey('approved', $summary);
    }

    public function test_category_writes_are_admin_gated(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/expense-categories', ['name' => 'Travel'])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/expense-categories', ['name' => 'Travel'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'travel');

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/financial/expense-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_expense_policy_crud_admin_gated(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/financial/expense-policies', ['name' => 'Default'])
            ->assertForbidden();

        $policy = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/financial/expense-policies', [
                'name' => 'Default',
                'receipt_required_over' => '25.00',
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/financial/expense-policies/'.$policy['id'], ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/financial/expense-policies/'.$policy['id'])
            ->assertOk();

        $this->assertDatabaseMissing('expense_policies', ['id' => $policy['id']]);
    }
}
