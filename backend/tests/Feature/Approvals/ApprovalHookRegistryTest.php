<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Agents\AgentArtifactApprovals;
use App\Services\Agents\AgentRunResumer;
use App\Services\ApprovalEngine;
use App\Services\Outreach\Bot\OutreachReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * config/approvals.php resource_hooks drive ApprovalEngine::fireResourceHook
 * on every resolution path. Regression: the outreach_reply hook fires from
 * the single-stage controller path (approve and reject) exactly as before,
 * and from a policy chain; a hook whose class is missing is skipped.
 */
class ApprovalHookRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Hooks Co', 'slug' => 'hooks-'.Str::lower(Str::random(6)), 'status' => 'active',
            'plan' => 'growth', 'automation_level' => 'assisted', 'onboarding_completed_at' => now(), 'settings' => [],
        ]);
        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);
    }

    private function approval(string $resourceType, string $resourceId): string
    {
        return app(ApprovalEngine::class)->createApproval(
            (string) $this->tenant->id, (string) $this->admin->id, $resourceType, $resourceType, $resourceId, 'test', ['action' => 'submit'],
        )['id'];
    }

    public function test_the_registry_maps_the_runtime_hooks_to_real_classes(): void
    {
        $hooks = config('approvals.resource_hooks');

        $this->assertSame([OutreachReplyService::class, 'onApprovalResolved'], $hooks['outreach_reply']);
        $this->assertSame([AgentArtifactApprovals::class, 'onApprovalResolved'], $hooks['agent_artifact']);
        $this->assertSame([AgentRunResumer::class, 'onApprovalResolved'], $hooks['agent_tool_call']);
        $this->assertArrayHasKey('brain_proposal', $hooks);
        foreach (['outreach_reply', 'agent_artifact', 'agent_tool_call'] as $type) {
            $this->assertTrue(class_exists($hooks[$type][0]), "{$type} hook class exists");
            $this->assertTrue(method_exists($hooks[$type][0], $hooks[$type][1]));
        }
    }

    public function test_outreach_reply_fires_on_the_single_stage_approve_and_reject_paths(): void
    {
        // approvals.resource_id is a uuid column: Postgres rejects anything else.
        $draftApprove = (string) Str::uuid();
        $draftReject = (string) Str::uuid();

        $mock = $this->mock(OutreachReplyService::class);
        $mock->shouldReceive('onApprovalResolved')->once()->with((string) $this->tenant->id, $draftApprove, true, 'Looks good.');
        $mock->shouldReceive('onApprovalResolved')->once()->with((string) $this->tenant->id, $draftReject, false, 'Not our voice.');

        $approveId = $this->approval('outreach_reply', $draftApprove);
        $rejectId = $this->approval('outreach_reply', $draftReject);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/approvals/{$approveId}/approve", ['reason' => 'Looks good.'])->assertOk()
            ->assertJsonPath('status', 'approved');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/approvals/{$rejectId}/reject", ['reason' => 'Not our voice.'])->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertSame('approved', DB::table('approvals')->where('id', $approveId)->value('status'));
        $this->assertSame('rejected', DB::table('approvals')->where('id', $rejectId)->value('status'));
    }

    public function test_outreach_reply_fires_when_a_policy_chain_completes(): void
    {
        $policy = ApprovalPolicy::create([
            'tenant_id' => $this->tenant->id, 'resource_type' => 'outreach_reply', 'action' => 'submit',
            'name' => 'Admin signs every reply', 'enabled' => true, 'priority' => 5, 'conditions' => [],
        ]);
        ApprovalPolicyStep::create(['approval_policy_id' => $policy->id, 'step_order' => 1, 'approver_type' => 'role', 'approver_role' => 'admin']);

        $draftChain = (string) Str::uuid();
        $mock = $this->mock(OutreachReplyService::class);
        $mock->shouldReceive('onApprovalResolved')->once()->with((string) $this->tenant->id, $draftChain, true, 'Ship it.');

        $approval = app(ApprovalEngine::class)->createChainedApproval(
            (string) $this->tenant->id, (string) $this->admin->id, 'outreach_reply', 'outreach_reply', $draftChain, 'test', ['action' => 'submit'],
        );
        $this->assertSame(1, $approval['current_step']);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/approvals/{$approval['id']}/approve", ['reason' => 'Ship it.'])->assertOk()
            ->assertJsonPath('status', 'approved');
    }

    public function test_a_hook_whose_class_is_missing_is_skipped_without_failing_the_approval(): void
    {
        config()->set('approvals.resource_hooks.ghost_thing', ['App\\Nope\\GhostService', 'onApprovalResolved']);
        $id = $this->approval('ghost_thing', (string) Str::uuid());

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/approvals/{$id}/approve", ['reason' => 'ok'])->assertOk();

        $this->assertSame('approved', DB::table('approvals')->where('id', $id)->value('status'));
    }

    public function test_engine_hook_is_public_and_config_driven(): void
    {
        $seen = [];
        $handler = new class($seen)
        {
            public function __construct(private array &$seen) {}

            public function onApprovalResolved(string $tenantId, string $resourceId, bool $granted, string $response = ''): void
            {
                $this->seen[] = [$tenantId, $resourceId, $granted, $response];
            }
        };
        $this->app->instance('Tests\\Feature\\Approvals\\CustomHookHandler', $handler);
        config()->set('approvals.resource_hooks.custom_thing', ['Tests\\Feature\\Approvals\\CustomHookHandler', 'onApprovalResolved']);

        app(ApprovalEngine::class)->fireResourceHook('custom_thing', (string) $this->tenant->id, 'c-1', false, 'expired');

        $this->assertSame([[(string) $this->tenant->id, 'c-1', false, 'expired']], $seen);
    }
}
