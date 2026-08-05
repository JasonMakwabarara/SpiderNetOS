<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\ApprovalStep;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiStageApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Chain Tenant',
            'slug' => 'chain-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role): User
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

    /**
     * member -> admin two-step chain for expense_report submissions over 5000.
     */
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

    public function test_policy_matching_respects_amount_threshold(): void
    {
        $tenant = $this->createTenant();
        $this->createTieredPolicy($tenant);
        $engine = app(ApprovalEngine::class);

        $this->assertNull(
            $engine->matchPolicy($tenant->id, 'expense_report', 'submit', ['amount' => '100.00'])
        );
        $this->assertNotNull(
            $engine->matchPolicy($tenant->id, 'expense_report', 'submit', ['amount' => '7500.00'])
        );
    }

    public function test_chained_approval_materializes_steps(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $policy = $this->createTieredPolicy($tenant);
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createChainedApproval(
            $tenant->id,
            $requester->id,
            'spend',
            'expense_report',
            (string) Str::uuid(),
            'Team offsite expenses',
            ['attributes' => ['amount' => '7500.00']],
            $policy,
        );

        $this->assertSame('pending', $approval['status']);
        $this->assertSame(1, $approval['current_step']);
        $this->assertDatabaseHas('approval_steps', [
            'approval_id' => $approval['id'], 'step_order' => 1, 'status' => 'pending',
        ]);
        $this->assertDatabaseHas('approval_steps', [
            'approval_id' => $approval['id'], 'step_order' => 2, 'status' => 'queued',
        ]);
        $this->assertNotNull(
            ApprovalStep::where('approval_id', $approval['id'])->where('step_order', 1)->value('expires_at')
        );
        $this->assertDatabaseHas('event_log', ['event_type' => 'approval.chain_started']);
    }

    public function test_falls_back_to_single_stage_when_no_policy_matches(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createChainedApproval(
            $tenant->id,
            $requester->id,
            'spend',
            'expense_report',
            (string) Str::uuid(),
            'Small expense',
            ['attributes' => ['amount' => '20.00']],
        );

        $this->assertNull($approval['current_step']);
        $this->assertSame(0, ApprovalStep::where('approval_id', $approval['id'])->count());
    }

    public function test_full_chain_grant_path_advances_then_approves(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $policy = $this->createTieredPolicy($tenant);
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createChainedApproval(
            $tenant->id, $requester->id, 'spend', 'expense_report',
            (string) Str::uuid(), 'Big expense', ['attributes' => ['amount' => '9000.00']], $policy,
        );

        $afterStep1 = $engine->resolveStep($approval['id'], $member->id, true, 'looks fine');
        $this->assertSame('pending', $afterStep1['status']);
        $this->assertSame(2, $afterStep1['current_step']);
        $this->assertDatabaseHas('approval_steps', [
            'approval_id' => $approval['id'], 'step_order' => 2, 'status' => 'pending',
        ]);

        $final = $engine->resolveStep($approval['id'], $admin->id, true, 'confirmed');
        $this->assertSame('approved', $final['status']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'approval.step_granted']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'approval.granted']);
    }

    public function test_rejection_kills_chain_and_skips_remaining(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $member = $this->createUser($tenant, 'member');
        $policy = $this->createTieredPolicy($tenant);
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createChainedApproval(
            $tenant->id, $requester->id, 'spend', 'expense_report',
            (string) Str::uuid(), 'Big expense', ['attributes' => ['amount' => '9000.00']], $policy,
        );

        $result = $engine->resolveStep($approval['id'], $member->id, false, 'not justified');

        $this->assertSame('rejected', $result['status']);
        $this->assertDatabaseHas('approval_steps', [
            'approval_id' => $approval['id'], 'step_order' => 1, 'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('approval_steps', [
            'approval_id' => $approval['id'], 'step_order' => 2, 'status' => 'skipped',
        ]);
    }

    public function test_unauthorized_role_cannot_act_on_step(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $viewer = $this->createUser($tenant, 'viewer');
        $policy = $this->createTieredPolicy($tenant);
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createChainedApproval(
            $tenant->id, $requester->id, 'spend', 'expense_report',
            (string) Str::uuid(), 'Big expense', ['attributes' => ['amount' => '9000.00']], $policy,
        );

        $this->expectException(\DomainException::class);
        $engine->resolveStep($approval['id'], $viewer->id, true);
    }

    public function test_delegation_lets_delegate_act(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $member = $this->createUser($tenant, 'member');
        $viewer = $this->createUser($tenant, 'viewer');
        $policy = $this->createTieredPolicy($tenant);
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createChainedApproval(
            $tenant->id, $requester->id, 'spend', 'expense_report',
            (string) Str::uuid(), 'Big expense', ['attributes' => ['amount' => '9000.00']], $policy,
        );

        $engine->delegateStep($approval['id'], $member->id, $viewer->id, 'covering for me');
        $this->assertDatabaseHas('event_log', ['event_type' => 'approval.step_delegated']);

        // The viewer could not normally act (see test above) but can as delegate.
        $result = $engine->resolveStep($approval['id'], $viewer->id, true, 'ok as delegate');
        $this->assertSame(2, $result['current_step']);
    }

    public function test_http_endpoints_route_chained_approvals_through_engine(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $policy = $this->createTieredPolicy($tenant);
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createChainedApproval(
            $tenant->id, $requester->id, 'spend', 'expense_report',
            (string) Str::uuid(), 'Big expense', ['attributes' => ['amount' => '9000.00']], $policy,
        );

        // show returns the chain
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/approvals/'.$approval['id'])
            ->assertOk()
            ->assertJsonPath('data.current_step', 1)
            ->assertJsonCount(2, 'data.steps');

        // step 1 via HTTP advances the chain
        $this->actingAs($member, 'sanctum')
            ->postJson('/api/approvals/'.$approval['id'].'/approve', ['reason' => 'fine'])
            ->assertOk()
            ->assertJsonPath('current_step', 2);

        // wrong-role actor gets 403
        $this->actingAs($this->createUser($tenant, 'viewer'), 'sanctum')
            ->postJson('/api/approvals/'.$approval['id'].'/approve')
            ->assertForbidden();

        // final step approves
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/approvals/'.$approval['id'].'/approve', ['reason' => 'done'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_policy_crud_api_is_admin_gated(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');

        $payload = [
            'name' => 'Bill chains',
            'resource_type' => 'bill',
            'conditions' => ['min_amount' => '1000.00'],
            'steps' => [
                ['approver_type' => 'role', 'approver_role' => 'admin', 'expires_after_hours' => 48],
            ],
        ];

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/approvals/policies', $payload)
            ->assertForbidden();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/approvals/policies', $payload)
            ->assertCreated()
            ->json('data');

        $this->assertCount(1, $created['steps']);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/approvals/policies')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Cross-tenant isolation: another tenant sees nothing.
        $otherAdmin = $this->createUser($this->createTenant(), 'admin');
        $this->actingAs($otherAdmin, 'sanctum')
            ->getJson('/api/approvals/policies')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_legacy_single_stage_flow_unchanged(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $engine = app(ApprovalEngine::class);

        $approval = $engine->createApproval(
            $tenant->id, $requester->id, 'manual', 'flow_execution',
            (string) Str::uuid(), 'legacy path',
        );

        $this->assertNull(DB::table('approvals')->where('id', $approval['id'])->value('current_step'));

        $resolved = $engine->resolveApproval($approval['id'], $admin->id, true, 'ok');
        $this->assertSame('approved', $resolved['status']);
    }
}
