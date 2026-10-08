<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\BusinessAsset;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who may decide an approval, and who may change what is being approved.
 *
 * The `approvals.decide` capability was declared in User::ROLE_CAPABILITIES
 * (admins hold it through `approvals.*`) and checked nowhere: any signed-in
 * user of the tenant — a viewer included — could approve anything
 * single-stage, and the approval hook then applied it. Authority now lives in
 * ApprovalEngine::decideSingleStage(), the one operation every caller uses, so
 * a job or command cannot route around a check that sits only on a route.
 */
class ApproverAuthorityTest extends AgentsTestCase
{
    public function test_a_member_can_neither_approve_nor_reject(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $member = $this->user('member');

        $this->actingAs($member, 'sanctum')->postJson("/api/approvals/{$approval->id}/approve")->assertStatus(403);
        $this->actingAs($member, 'sanctum')->postJson("/api/approvals/{$approval->id}/reject", ['reason' => 'no'])->assertStatus(403);

        $this->assertUndecided($run, $approval->id);
    }

    public function test_a_viewer_cannot_approve(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $this->actingAs($this->user('viewer'), 'sanctum')->postJson("/api/approvals/{$approval->id}/approve")->assertStatus(403);

        $this->assertUndecided($run, $approval->id);
    }

    /** The capability decides, not the role name: a member granted it may approve. */
    public function test_the_capability_not_the_role_name_grants_authority(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $member = $this->user('member', ['approvals.decide']);

        $this->actingAs($member, 'sanctum')->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $this->shownVersion($approval->id)])->assertOk();

        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count());
        $this->assertSame((string) $member->id, (string) DB::table('approvals')->where('id', $approval->id)->value('approver_id'));
    }

    /**
     * The operation enforces authority itself, for every caller — not only
     * behind the HTTP route. resolveApproval() is the second caller.
     */
    public function test_the_shared_operation_refuses_an_unauthorised_actor_whoever_calls_it(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $engine = app(ApprovalEngine::class);
        $tenant = (string) $this->tenant->id;
        $otherTenantAdmin = $this->user('admin', [], $this->otherTenant());

        foreach ([
            'a member' => fn () => $engine->decideSingleStage($tenant, $approval->id, $this->user('member'), true, null),
            'no actor' => fn () => $engine->decideSingleStage($tenant, $approval->id, null, true, null),
            "another tenant's admin" => fn () => $engine->decideSingleStage($tenant, $approval->id, $otherTenantAdmin, true, null),
            'a member via resolveApproval()' => fn () => $engine->resolveApproval($approval->id, (string) $this->user('member')->id, true),
        ] as $who => $attempt) {
            try {
                $attempt();
                $this->fail("{$who} decided an approval");
            } catch (\DomainException) {
                // refused, as required
            }
        }

        $this->assertUndecided($run, $approval->id);
    }

    /**
     * resolveApproval() now makes the same decision the controller makes —
     * one event keyed to the approval, and the resource hook. Before, it
     * wrote its own event under a random aggregate id and never fired the
     * hook, although config/approvals.php says every path does.
     */
    public function test_resolve_approval_makes_the_same_decision_as_the_route(): void
    {
        [$run, $approval] = $this->pendingSequence();

        app(ApprovalEngine::class)->resolveApproval($approval->id, (string) $this->admin->id, true, 'ok', $this->shownVersion($approval->id));

        $this->assertSame(1, Event::forTenant((string) $this->tenant->id)->where('event_type', 'approval.granted')->where('aggregate_id', $approval->id)->count());
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count(), 'the hook fired');
    }

    /** Editing content under review changes what the approver approves. */
    public function test_editing_content_under_review_needs_an_approver(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $email = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->firstOrFail();
        $edit = ['content' => "Subject: Something else\n\nA body the approver never saw.", 'expected_version' => $this->shownVersion($approval->id)];

        $this->actingAs($this->user('viewer'), 'sanctum')->patchJson("/api/artifacts/{$email->id}", $edit)
            ->assertStatus(403)->assertJsonPath('reason', 'insufficient_role');
        $this->actingAs($this->user('member'), 'sanctum')->patchJson("/api/artifacts/{$email->id}", $edit)
            ->assertStatus(403)->assertJsonPath('reason', 'approver_capability_required');
        $this->assertSame($email->content, AgentArtifact::findOrFail($email->id)->content, 'nothing changed');

        $this->api()->patchJson("/api/artifacts/{$email->id}", $edit)->assertOk();
    }

    /**
     * Put in the one state apply acts on — approved, not yet applied, which
     * is what an interrupted apply leaves — so the refusal can only come from
     * the capability: a submitted artifact is refused anyway (409), which
     * would let this test pass with no authority check at all.
     */
    public function test_applying_needs_an_approver(): void
    {
        [$run, $approval] = $this->pendingSequence();
        DB::table('approvals')->where('id', $approval->id)->update(['status' => 'approved', 'approved_version_hash' => $this->shownVersion($approval->id)]);
        AgentArtifact::where('run_id', $run->id)->update(['status' => AgentArtifact::STATUS_APPROVED]);
        $sequence = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole();

        $this->actingAs($this->user('member'), 'sanctum')->postJson("/api/artifacts/{$sequence->id}/apply")->assertStatus(403);
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count());

        // The state was appliable: an approver applies it.
        $this->api()->postJson("/api/artifacts/{$sequence->id}/apply")->assertOk();
        $this->assertSame(1, BusinessAsset::forTenant((string) $this->tenant->id)->count());
    }

    /** Enabling installs a skill and grants its first autonomy rung. */
    public function test_enabling_a_skill_needs_an_admin(): void
    {
        $this->actingAs($this->user('member'), 'sanctum')->postJson('/api/skills/cold-email-drafting/enable')
            ->assertStatus(403)->assertJsonPath('reason', 'insufficient_role');
    }

    // ------------------------------------------------------------------ //

    /** @return array{0: AgentRun, 1: object} */
    private function pendingSequence(): array
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

        return [$run, $this->approvals('agent_artifact', 'pending')->sole()];
    }

    /** @param list<string> $capabilities */
    private function user(string $role, array $capabilities = [], ?Tenant $tenant = null): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'role' => $role, 'capabilities' => $capabilities,
            'onboarding_completed_at' => now(),
        ]);
    }

    private function otherTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other Co', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'automation_level' => 'assisted',
            'onboarding_completed_at' => now(), 'settings' => [],
        ]);
    }

    private function assertUndecided(AgentRun $run, string $approvalId): void
    {
        $this->assertSame('pending', DB::table('approvals')->where('id', $approvalId)->value('status'));
        $this->assertSame(0, Event::forTenant((string) $this->tenant->id)->whereIn('event_type', ['approval.granted', 'approval.rejected'])->count());
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_SUBMITTED)->count());
    }
}
