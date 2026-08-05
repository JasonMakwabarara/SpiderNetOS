<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Expiry Tenant',
            'slug' => 'expiry-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createPolicy(Tenant $tenant, ?string $escalateTo): ApprovalPolicy
    {
        $policy = ApprovalPolicy::create([
            'tenant_id' => $tenant->id,
            'resource_type' => 'expense_report',
            'action' => 'submit',
            'name' => 'Expiring chain',
            'enabled' => true,
            'conditions' => null,
        ]);

        ApprovalPolicyStep::create([
            'approval_policy_id' => $policy->id,
            'step_order' => 1,
            'approver_type' => 'role',
            'approver_role' => 'member',
            'expires_after_hours' => 24,
            'escalate_to_role' => $escalateTo,
        ]);

        return $policy->fresh('steps');
    }

    public function test_overdue_step_without_escalation_expires_approval(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $engine = app(ApprovalEngine::class);
        $policy = $this->createPolicy($tenant, null);

        $approval = $engine->createChainedApproval(
            $tenant->id, $requester->id, 'spend', 'expense_report',
            (string) Str::uuid(), 'stale request', [], $policy,
        );

        $this->travel(25)->hours();

        $acted = $engine->expireOverdueSteps();

        $this->assertSame(1, $acted);
        $this->assertDatabaseHas('approvals', ['id' => $approval['id'], 'status' => 'expired']);
        $this->assertDatabaseHas('approval_steps', [
            'approval_id' => $approval['id'], 'step_order' => 1, 'status' => 'expired',
        ]);
        $this->assertDatabaseHas('event_log', ['event_type' => 'approval.step_expired']);
        $this->assertDatabaseHas('event_log', ['event_type' => 'approval.expired']);
    }

    public function test_overdue_step_with_escalation_swaps_role_and_extends(): void
    {
        $tenant = $this->createTenant();
        $requester = $this->createUser($tenant, 'member');
        $admin = $this->createUser($tenant, 'admin');
        $engine = app(ApprovalEngine::class);
        $policy = $this->createPolicy($tenant, 'admin');

        $approval = $engine->createChainedApproval(
            $tenant->id, $requester->id, 'spend', 'expense_report',
            (string) Str::uuid(), 'needs escalation', [], $policy,
        );

        $this->travel(25)->hours();

        $acted = $engine->expireOverdueSteps();

        $this->assertSame(1, $acted);
        // Approval still live, step now owned by the escalation role with a
        // fresh deadline.
        $this->assertDatabaseHas('approvals', ['id' => $approval['id'], 'status' => 'pending']);
        $this->assertDatabaseHas('approval_steps', [
            'approval_id' => $approval['id'],
            'step_order' => 1,
            'status' => 'pending',
            'approver_role' => 'admin',
        ]);
        $this->assertDatabaseHas('event_log', ['event_type' => 'approval.step_escalated']);

        // The escalation-role user can now resolve it.
        $result = $engine->resolveStep($approval['id'], $admin->id, true, 'escalated approval');
        $this->assertSame('approved', $result['status']);

        // Sweep is idempotent afterwards.
        $this->assertSame(0, $engine->expireOverdueSteps());
    }
}
