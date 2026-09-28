<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\ApprovalPolicy;
use App\Models\ApprovalPolicyStep;
use App\Models\BusinessAsset;
use App\Models\MessageTemplate;
use App\Services\Agents\AgentArtifactApprovals;
use App\Services\Agents\ApplicationPayload;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An approval authorises exactly the version its approver saw.
 *
 * Before this, an approval was tied to an artifact id and nothing else: an
 * edit after the approver's page loaded was approved sight unseen, the
 * preview, the hash-less approval and the applier each read a different
 * source, a step could be re-pointed at another run's artifact, and an edit
 * reached only the first variant of a step.
 */
class VersionBindingTest extends AgentsTestCase
{
    public function test_the_approval_shows_and_binds_the_payload_it_would_apply(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $sequence = $this->sequence($run);
        $payload = ApplicationPayload::for($sequence);

        $this->assertSame(ApplicationPayload::hash($payload), $approval->version_hash);
        // Compared as the hash, since the stored context is JSON (Postgres
        // `jsonb` reorders its keys); what it shows is what is bound.
        $this->assertSame($approval->version_hash, ApplicationPayload::hash($this->approvalContext($approval)['payload']));
        $this->assertSame(['a', 'b'], array_column($payload['steps'][0]['variants'], 'key'));
    }

    public function test_a_grant_must_present_the_version_it_saw(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $this->api()->postJson("/api/approvals/{$approval->id}/approve")
            ->assertStatus(409)->assertJsonPath('reason', 'version_missing');

        $this->assertNothingDecided($run, $approval->id);
    }

    /**
     * An edit supersedes the version an earlier page showed. The stale page
     * is refused; the approver who made or saw the edit approves the new
     * version, and that is what is applied.
     */
    public function test_an_edit_supersedes_the_version_an_earlier_page_showed(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $seenBefore = $this->shownVersion($approval->id);

        $seenAfter = $this->api()->patchJson("/api/artifacts/{$this->stepEmail($run, 1)->id}", [
            'content' => "Subject: A shorter opener\n\n{{first_line}} Slow follow-up costs agencies good work.",
            'expected_version' => $seenBefore,
        ])->assertOk()->json('data.approval_version_hash');

        $this->assertNotSame($seenBefore, $seenAfter);
        $this->assertSame($seenAfter, $this->shownVersion($approval->id));

        $this->api()->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $seenBefore])
            ->assertStatus(409)->assertJsonPath('reason', 'version_stale');
        $this->assertNothingDecided($run, $approval->id);

        $this->api()->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $seenAfter])->assertOk();
        $this->assertSame($seenAfter, DB::table('approvals')->where('id', $approval->id)->value('approved_version_hash'));
        $this->assertStringContainsString('Slow follow-up costs agencies good work.', $this->template('outreach.spring-launch.step1.a')->body);
    }

    /**
     * Variant `b` carries its own subject line but the step's body. An edit
     * reaches every variant that held the text it replaced: `b` takes the new
     * body and keeps its own subject. It used to keep the model's original
     * body — sent although the approver had rewritten it.
     */
    public function test_an_edit_reaches_every_variant_that_carried_the_original_text(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $this->api()->patchJson("/api/artifacts/{$this->stepEmail($run, 1)->id}", [
            'content' => "Subject: A shorter opener\n\n{{first_line}} Slow follow-up costs agencies good work.",
            'expected_version' => $this->shownVersion($approval->id),
        ])->assertOk();

        $this->approve($approval->id);

        $a = $this->template('outreach.spring-launch.step1.a');
        $b = $this->template('outreach.spring-launch.step1.b');
        $this->assertSame(['A shorter opener', '{{first_line}} Slow follow-up costs agencies good work.'], [$a->subject, $a->body]);
        $this->assertSame(['The reply that never went out', '{{first_line}} Slow follow-up costs agencies good work.'], [$b->subject, $b->body]);
    }

    /** A review that has been decided cannot change underneath its decision. */
    public function test_content_is_frozen_once_the_review_is_decided(): void
    {
        [$run, $approval] = $this->pendingSequence();
        // Decided, not yet applied: the state between the decision's commit
        // and its hook.
        DB::table('approvals')->where('id', $approval->id)->update(['status' => 'approved']);

        $this->api()->patchJson("/api/artifacts/{$this->stepEmail($run, 1)->id}", ['content' => "Subject: X\n\nSomething else."])
            ->assertStatus(409)->assertJsonPath('error', 'approval_decided');
    }

    /** Where a bundle goes and what it is made of are not content edits. */
    public function test_structural_meta_is_refused_not_stripped(): void
    {
        [$run] = $this->pendingSequence();
        $sequence = $this->sequence($run);

        foreach ([['steps' => []], ['campaign_key' => 'elsewhere'], ['artifact_ids' => []], ['sequence_id' => (string) Str::uuid()]] as $meta) {
            $this->api()->patchJson("/api/artifacts/{$sequence->id}", ['meta' => $meta])
                ->assertStatus(422)->assertJsonPath('keys', array_keys($meta));
        }
        $this->assertSame((array) $sequence->meta, (array) $sequence->fresh()->meta);
    }

    /**
     * A step can no longer be pointed at another run's artifact. The bundle
     * fails its integrity check when approved, and nothing is applied.
     */
    public function test_a_step_pointing_at_another_runs_artifact_is_refused(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $this->model($this->validSequenceCompletion('Autumn Push'));
        $otherRun = $this->startRun(array_merge($this->defaultInputs(), ['campaign' => 'Autumn Push']), 'other-run');
        $foreign = $this->stepEmail($otherRun, 1);

        $sequence = $this->sequence($run);
        $meta = (array) $sequence->meta;
        $original = (string) $meta['steps'][0]['artifact_id'];
        $meta['steps'][0]['artifact_id'] = (string) $foreign->id;
        $meta['artifact_ids'] = array_values(array_map(fn ($id) => $id === $original ? (string) $foreign->id : $id, $meta['artifact_ids']));
        $sequence->forceFill(['meta' => $meta])->save();

        $this->api()->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $this->shownVersion($approval->id)])
            ->assertStatus(409)->assertJsonPath('reason', 'bundle_integrity');

        $this->assertNothingDecided($run, $approval->id);
    }

    /**
     * The hook applies only for the approval that covers the bundle now, and
     * only a version that approval bound.
     */
    public function test_the_hook_refuses_an_approval_that_does_not_cover_the_bundle(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $hook = app(AgentArtifactApprovals::class);
        $version = $this->shownVersion($approval->id);

        foreach ([
            'another approval' => ['approval_id' => (string) Str::uuid(), 'approved_version_hash' => $version],
            'no approval named' => ['approved_version_hash' => $version],
            'no version bound' => ['approval_id' => (string) $approval->id, 'approved_version_hash' => null],
        ] as $case => $decision) {
            try {
                $hook->onApprovalResolved((string) $this->tenant->id, (string) $approval->resource_id, true, '', $decision);
                $this->fail("the hook applied for {$case}");
            } catch (BundleIntegrityException) {
                // refused, as required
            }
        }

        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count());
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_SUBMITTED)->count());
    }

    /**
     * Applying after the fact checks the payload against the version the
     * decision bound. Content changed since then is not applied; unchanged
     * content is.
     */
    public function test_apply_refuses_content_changed_after_approval(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $sequence = $this->sequence($run);
        // Approved and bound, not yet applied — what an interrupted apply leaves.
        DB::table('approvals')->where('id', $approval->id)->update(['status' => 'approved', 'approved_version_hash' => $this->shownVersion($approval->id)]);
        AgentArtifact::where('run_id', $run->id)->update(['status' => AgentArtifact::STATUS_APPROVED]);
        $email = $this->stepEmail($run, 2);
        $untouched = (string) $email->content;
        $email->forceFill(['content' => "Subject: Changed\n\nNot what was approved."])->save();

        $this->api()->postJson("/api/artifacts/{$sequence->id}/apply")->assertStatus(409)->assertJsonPath('error', 'bundle_integrity');
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count());

        // Restored to the approved text, the same bundle applies.
        $email->forceFill(['content' => $untouched])->save();
        $this->api()->postJson("/api/artifacts/{$sequence->id}/apply")->assertOk();
        $this->assertSame(1, BusinessAsset::forTenant((string) $this->tenant->id)->count());
    }

    /** An approval never bound to a version cannot be approved — reject and resubmit. */
    public function test_an_unbound_approval_is_refused_rather_than_applied_unbound(): void
    {
        [$run, $approval] = $this->pendingSequence();
        $version = $this->shownVersion($approval->id);
        DB::table('approvals')->where('id', $approval->id)->update(['version_hash' => null]);

        $this->api()->postJson("/api/approvals/{$approval->id}/approve", ['version_hash' => $version])
            ->assertStatus(409)->assertJsonPath('reason', 'version_unbound');

        $this->assertNothingDecided($run, $approval->id);
    }

    /** Rejecting authorises nothing, so it needs no version. */
    public function test_a_rejection_needs_no_version(): void
    {
        [$run, $approval] = $this->pendingSequence();

        $this->approve($approval->id, grant: false);

        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_REJECTED)->count());
    }

    /** A chain binds the version too: each approving step decides the version it saw. */
    public function test_a_chain_step_must_present_the_version_and_the_chain_binds_it(): void
    {
        $policy = ApprovalPolicy::create([
            'tenant_id' => $this->tenant->id, 'resource_type' => 'agent_artifact', 'action' => 'submit',
            'name' => 'An admin signs every draft', 'enabled' => true, 'priority' => 5, 'conditions' => [],
        ]);
        ApprovalPolicyStep::create(['approval_policy_id' => $policy->id, 'step_order' => 1, 'approver_type' => 'role', 'approver_role' => 'admin']);
        [$run, $approval] = $this->pendingSequence();
        $this->assertSame(1, (int) $approval->current_step, 'the policy made this a chain');

        $this->api()->postJson("/api/approvals/{$approval->id}/approve")->assertStatus(409)->assertJsonPath('reason', 'version_missing');

        $this->approve($approval->id);
        $this->assertSame($approval->version_hash, DB::table('approvals')->where('id', $approval->id)->value('approved_version_hash'));
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_APPLIED)->count());
    }

    // ------------------------------------------------------------------ //

    /** @return array{0: AgentRun, 1: object} */
    private function pendingSequence(): array
    {
        $this->seedBrain();
        $this->model($this->validSequenceCompletion());
        $run = $this->startRun();
        $this->assertSame(AgentRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);

        return [$run, $this->approvals('agent_artifact', 'pending')->firstWhere('resource_id', $this->sequence($run)->id)];
    }

    private function sequence(AgentRun $run): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->sole();
    }

    private function stepEmail(AgentRun $run, int $n): AgentArtifact
    {
        return AgentArtifact::where('run_id', $run->id)->where('kind', AgentArtifact::KIND_DRAFT_EMAIL)->get()->first(fn ($a) => (int) $a->meta['n'] === $n);
    }

    private function template(string $key): MessageTemplate
    {
        return MessageTemplate::forTenant((string) $this->tenant->id)->where('key', $key)->firstOrFail();
    }

    private function assertNothingDecided(AgentRun $run, string $approvalId): void
    {
        $this->assertSame('pending', DB::table('approvals')->where('id', $approvalId)->value('status'));
        $this->assertNull(DB::table('approvals')->where('id', $approvalId)->value('approved_version_hash'));
        $this->assertSame(4, AgentArtifact::where('run_id', $run->id)->where('status', AgentArtifact::STATUS_SUBMITTED)->count());
        $this->assertSame(0, BusinessAsset::forTenant((string) $this->tenant->id)->count());
    }
}
