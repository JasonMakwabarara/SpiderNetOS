<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use Illuminate\Support\Facades\DB;

/**
 * Each write the agent_artifact hook makes fails the way its purpose
 * requires (AgentArtifactApprovals' docblock carries the policy). A write is
 * made to fail by renaming its table inside the test's own transaction, which
 * rolls back with it.
 *
 * On Postgres these also prove the savepoints: a failed statement there
 * aborts the enclosing transaction, so a best-effort failure that did not
 * roll back to its own savepoint would take the approval down with it. The
 * full Postgres lane runs this class.
 */
class ApprovalHookWritesTest extends AgentsTestCase
{
    /**
     * A streak that survived a rejection would count the next clean approvals
     * as if nothing had happened, so the reset is required. Its failure rolls
     * the hook back and leaves the bundle submitted — re-deliverable, not
     * silently credited.
     *
     * The approval itself stays `rejected`: the decision commits before the
     * hook runs. That is the committed-but-unexecuted gap in the awareness
     * list, and it is what durable action records are for.
     */
    public function test_a_failed_streak_reset_rolls_the_hook_back(): void
    {
        [$run, $approval] = $this->pendingSequence();
        DB::statement('ALTER TABLE tenant_skills RENAME TO tenant_skills_unavailable');

        $this->api()->postJson("/api/approvals/{$approval->id}/reject", ['reason' => 'Not our voice.'])->assertStatus(500);

        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_SUBMITTED)->count());
        $this->assertCount(0, $this->events('agent.artifact.rejected'));
        $this->assertSame('rejected', DB::table('approvals')->where('id', $approval->id)->value('status'));
    }

    /** A lost increment under-counts, the safe direction: the approval completes. */
    public function test_a_failed_streak_increment_does_not_undo_the_approval(): void
    {
        [$run, $approval] = $this->pendingSequence();
        DB::statement('ALTER TABLE tenant_skills RENAME TO tenant_skills_unavailable');

        $this->approve($approval->id);

        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count());
        $this->assertCount(1, $this->events('agent.artifact.approved'));
        $this->assertCount(1, $this->events('agent.artifact.applied'));
    }

    /**
     * An edit whose revision could not be recorded is marked, so its size
     * reads as unknown rather than as a clean draft. The streak still resets:
     * the edit is known even when its size is not.
     */
    public function test_an_unrecorded_revision_is_marked_not_read_as_clean(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $stepOne = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get()->first(fn ($a) => (int) $a->meta['n'] === 1);
        $this->api()->patchJson("/api/artifacts/{$stepOne->id}", [
            'content' => "Subject: Leads are going cold in your inbox\n\n{{first_line}} A shorter opener, in our own words.",
        ])->assertOk();
        DB::statement('ALTER TABLE artifact_revisions RENAME TO artifact_revisions_unavailable');

        $this->approve($approval->id);

        $this->assertTrue((bool) (AgentArtifact::findOrFail($stepOne->id)->meta['revision_unrecorded'] ?? false));
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count());
        $this->assertSame(0, (int) DB::table('tenant_skills')->where('tenant_id', $this->tenant->id)->where('skill_slug', 'cold-email-drafting')->value('clean_drafts_count'));
    }

    /** The projection may fail without undoing the application, but the application says so. */
    public function test_a_failed_brain_projection_is_visible_on_the_application(): void
    {
        [$run] = $this->pendingSequence();
        $approval = $this->approvals('agent_artifact', 'pending')->sole();
        DB::statement('ALTER TABLE brain_files RENAME TO brain_files_unavailable');

        $this->approve($approval->id);

        $sequence = AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole();
        $this->assertSame(AgentArtifact::STATUS_APPLIED, $sequence->status);
        $this->assertStringEndsWith(';brain_projection:failed', (string) $sequence->applied_ref);
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
}
