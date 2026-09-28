<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\BusinessAsset;
use App\Models\Event;
use App\Models\User;
use App\Services\Agents\ApplicationPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One review, one revision.
 *
 * Commit 4 bound each decision to the version its decider saw. That alone
 * leaves three gaps, each closed here:
 *
 *   a chain   step 1 approves V1, the content is edited to V2, step 2
 *             approves V2 — every request passed its own version check, and
 *             the chain would complete on content step 1 never saw
 *   editors   two editors load V1; both save in turn; the second silently
 *             overwrites the first, having started from content that is gone
 *   rejection a reviewer looking at V1 rejects V2 after someone corrected it,
 *             and the rejection is attributed to content they never examined
 */
class ReviewRevisionTest extends AgentsTestCase
{
    // ---- chains ---------------------------------------------------------

    public function test_every_approving_step_records_the_version_it_approved(): void
    {
        $this->chainOf(['admin', 'admin']);
        [$run, $approval] = $this->pendingSequence();
        $version = $this->shownVersion($approval->id);

        $this->approve($approval->id);
        $this->approve($approval->id);

        $this->assertSame([$version, $version], DB::table('approval_steps')->where('approval_id', $approval->id)->orderBy('step_order')->pluck('version_hash')->all());
        $this->assertSame($version, DB::table('approvals')->where('id', $approval->id)->value('approved_version_hash'));
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count());
    }

    /** Once a step has approved, the content is what it approved: edits are refused. */
    public function test_content_is_frozen_once_a_chain_step_has_approved(): void
    {
        $this->chainOf(['admin', 'admin']);
        [$run, $approval] = $this->pendingSequence();
        $this->approve($approval->id);

        $this->api()->patchJson("/api/artifacts/{$this->stepEmail($run, 1)->id}", [
            'content' => "Subject: Changed\n\nAfter step one approved.",
            'expected_version' => $this->shownVersion($approval->id),
        ])->assertStatus(409)->assertJsonPath('error', 'review_in_progress');
    }

    /**
     * The decisive case: step 1 approves V1; the content becomes V2 by a route
     * that slips past the edit freeze; step 2 approves V2 — its own check
     * passes. The chain still cannot complete, because step 1 never approved
     * V2, and nothing is applied.
     */
    public function test_an_earlier_approval_cannot_carry_forward_onto_different_content(): void
    {
        $this->chainOf(['admin', 'admin']);
        [$run, $approval] = $this->pendingSequence();
        $this->approve($approval->id);

        $email = $this->stepEmail($run, 1);
        $email->forceFill(['content' => "Subject: Slipped past\n\nContent step one never saw."])->save();
        $v2 = ApplicationPayload::hash(ApplicationPayload::for($this->sequence($run)));
        DB::table('approvals')->where('id', $approval->id)->update(['version_hash' => $v2]);

        $this->api()->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $v2])
            ->assertStatus(409)->assertJsonPath('reason', 'version_stale');

        $this->assertSame('pending', DB::table('approvals')->where('id', $approval->id)->value('status'));
        $this->assertSame('pending', DB::table('approval_steps')->where('approval_id', $approval->id)->where('step_order', 2)->value('status'));
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count());
    }

    /**
     * A named reviewer stays required: an administrator cannot stand in for
     * them. (A role step admits anyone at or above the role; a user step only
     * that user or their delegate.)
     */
    public function test_an_administrator_cannot_stand_in_for_a_named_reviewer(): void
    {
        $reviewer = User::create([
            'name' => 'Named', 'email' => 'named@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'member', 'onboarding_completed_at' => now(),
        ]);
        $this->chainOf([$reviewer]);
        [$run, $approval] = $this->pendingSequence();
        $shown = ['version_hash' => $this->shownVersion($approval->id)];

        $this->api()->postJson("/api/approvals/{$approval->id}/approve", $shown)->assertStatus(403);
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/approvals/{$approval->id}/approve", $shown)->assertOk();

        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count());
    }

    // ---- editors --------------------------------------------------------

    /** The second editor started from content that is gone, and is told so. */
    public function test_a_stale_editor_cannot_overwrite_a_newer_save(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $loaded = $this->shownVersion($approval->id);
        $email = $this->stepEmail($run, 1);

        $this->api()->patchJson("/api/artifacts/{$email->id}", ['content' => "Subject: First editor\n\nSaved first.", 'expected_version' => $loaded])->assertOk();
        $this->api()->patchJson("/api/artifacts/{$email->id}", ['content' => "Subject: Second editor\n\nFrom the old page.", 'expected_version' => $loaded])
            ->assertStatus(409)->assertJsonPath('error', 'edit_stale');

        $this->assertSame("Subject: First editor\n\nSaved first.", $email->fresh()->content);
    }

    public function test_an_edit_under_review_must_say_which_version_it_started_from(): void
    {
        [$run] = $this->pendingSequence();

        $this->api()->patchJson("/api/artifacts/{$this->stepEmail($run, 1)->id}", ['content' => "Subject: X\n\nNo version."])
            ->assertStatus(409)->assertJsonPath('error', 'edit_version_missing');
    }

    // ---- rejections -----------------------------------------------------

    /** A rejection judges a version: one that changed since is refused as stale. */
    public function test_a_rejection_of_content_that_changed_is_refused(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $seen = $this->shownVersion($approval->id);
        $this->api()->patchJson("/api/artifacts/{$this->stepEmail($run, 1)->id}", ['content' => "Subject: Corrected\n\nSomeone fixed it.", 'expected_version' => $seen])->assertOk();

        $this->api()->postJson("/api/approvals/{$approval->id}/reject", ['reason' => 'Not our voice.', 'version_hash' => $seen])
            ->assertStatus(409)->assertJsonPath('reason', 'version_stale');
        $this->assertSame('pending', DB::table('approvals')->where('id', $approval->id)->value('status'));

        $current = $this->shownVersion($approval->id);
        $this->api()->postJson("/api/approvals/{$approval->id}/reject", ['reason' => 'Still not our voice.', 'version_hash' => $current])->assertOk();
        $this->assertSame($current, $this->decisionEvent($approval->id)->payload['observed_version_hash']);
    }

    /** Without a version it is a cancellation, and the record says nothing was observed. */
    public function test_a_rejection_without_a_version_is_recorded_as_observing_none(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $this->api()->postJson("/api/approvals/{$approval->id}/reject", ['reason' => 'Withdrawn.'])->assertOk();

        $this->assertNull($this->decisionEvent($approval->id)->payload['observed_version_hash']);
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_REJECTED)->count());
    }

    // ------------------------------------------------------------------ //

    /** @param list<string|User> $approvers roles, or named users */
    private function chainOf(array $approvers): void
    {
        $policy = ApprovalPolicy::create([
            'tenant_id' => $this->tenant->id, 'resource_type' => 'agent_artifact', 'action' => 'submit',
            'name' => 'Every draft is signed', 'enabled' => true, 'priority' => 5, 'conditions' => [],
        ]);
        foreach (array_values($approvers) as $i => $approver) {
            ApprovalPolicyStep::create(['approval_policy_id' => $policy->id, 'step_order' => $i + 1] + ($approver instanceof User
                ? ['approver_type' => 'user', 'approver_id' => $approver->id]
                : ['approver_type' => 'role', 'approver_role' => $approver]));
        }
    }

    /** @return array{0: AgentRun, 1: object} */
    private function pendingSequence(): array
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

        return [$run, $this->approvals('agent_artifact', 'pending')->sole()];
    }

    private function sequence(AgentRun $run): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole();
    }

    private function stepEmail(AgentRun $run, int $n): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get()->first(fn ($a) => (int) $a->meta['n'] === $n);
    }

    private function decisionEvent(string $approvalId): Event
    {
        return Event::forTenant((string) $this->tenant->id)->where('aggregate_id', $approvalId)->whereIn('event_type', ['approval.granted', 'approval.rejected'])->sole();
    }
}
