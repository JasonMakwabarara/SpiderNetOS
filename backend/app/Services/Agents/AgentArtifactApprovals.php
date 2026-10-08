<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Events\AgentRunUpdated;
use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\TenantSkill;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Approval hook for resource type `agent_artifact` (config/approvals.php).
 * The approval's resource_id is the submitted artifact (a sequence covers
 * its step emails). Granted → every artifact in the bundle `approved` and
 * ArtifactApplier applies it; rejected → `rejected`. When the approver
 * edited an artifact before deciding (PATCH /artifacts/{id} keeps
 * meta.original_content) the edit is recorded through RevisionRecorder
 * (D8 #1) and the skill's clean_drafts_count resets; a clean approval
 * counts one toward the promotion gate.
 *
 * The decision is made once, under a lock. The hook used to read the
 * artifact's status, find it `submitted`, and go on — so two resolutions
 * reaching it together both applied the bundle and both counted a clean
 * draft. It now runs one short transaction that locks the whole bundle in
 * id order, re-reads the status under that lock, and only then transitions,
 * applies, records evidence and appends its events; the loser finds the
 * bundle already decided and does nothing. The broadcast waits for commit.
 *
 * Each write inside the transaction fails the way its purpose requires —
 * decided per write, because "best effort" is safe for some and silently
 * wrong for others:
 *
 *   status, application, events   required: a failure rolls the whole
 *                                  decision back and leaves it re-deliverable
 *   clean-draft reset (edit,       required: a streak that survives a
 *   rejection)                     rejection or an edit inflates promotion
 *                                  evidence
 *   clean-draft increment          best effort: failing under-counts, the
 *                                  safe direction
 *   revision record                best effort, but never silent: the
 *                                  artifact is marked `revision_unrecorded`
 *                                  so the edit's size reads as unknown, not
 *                                  as clean
 *   tripwire evaluation            best effort: it is re-run on the next
 *                                  decision; a tripwire must not fail an
 *                                  approval
 *
 * Best-effort writes run in savepoints (BestEffort), so a failed one cannot
 * abort the enclosing transaction on Postgres.
 *
 * A grant applies exactly the version that was approved, and only for the
 * approval that covers the bundle now. The hook receives the deciding
 * approval's identity and the version it bound; an approval that no longer
 * covers these artifacts, or a grant that bound no version, applies nothing
 * and rolls back. The applier then compares the version with the payload it
 * is about to write.
 */
final class AgentArtifactApprovals
{
    private const DECIDED = [AgentArtifact::STATUS_APPROVED, AgentArtifact::STATUS_REJECTED, AgentArtifact::STATUS_APPLIED];

    public function __construct(
        private readonly ArtifactApplier $applier,
        private readonly EventStore $events,
        private readonly WorkspaceProvisioner $workspaces,
        private readonly AgentCircuitBreaker $breaker,
    ) {}

    /**
     * @param  array{approval_id?: string, approved_version_hash?: ?string}  $decision  which approval decided, and the version it bound
     */
    public function onApprovalResolved(string $tenantId, string $resourceId, bool $granted, string $response = '', array $decision = []): void
    {
        // id and approval_id are uuid columns; a non-uuid resource id can only be "unknown".
        $found = Str::isUuid($resourceId)
            ? (AgentArtifact::forTenant($tenantId)->find($resourceId)
                ?? AgentArtifact::forTenant($tenantId)->where('approval_id', $resourceId)->orderByRaw("case when kind = 'draft_sequence' then 0 else 1 end")->first())
            : null;

        if ($found === null) {
            Log::warning('agent_artifact approval resolved for an unknown artifact', ['tenant_id' => $tenantId, 'resource_id' => $resourceId]);

            return;
        }
        if (in_array($found->status, self::DECIDED, true)) {
            return; // fast path; the decision below is re-made under the lock
        }

        $decided = DB::transaction(fn (): ?AgentArtifact => $this->decide($tenantId, $found, $granted, $response, $decision));

        if ($decided !== null) {
            // Runs now when there is no enclosing transaction, or when the
            // chain path's enclosing one commits — never on rolled-back state.
            DB::afterCommit(fn () => $this->broadcast($decided));
        }
    }

    /**
     * The version binding for `agent_artifact` approvals (config/approvals.php
     * `version_bindings`): lock the bundle — before the approval row, the
     * order every writer uses — and hash what approving it would apply now.
     *
     * @throws BundleIntegrityException
     */
    public function currentVersion(string $tenantId, object $approval): string
    {
        $primary = AgentArtifact::forTenant($tenantId)->find((string) $approval->resource_id);
        if ($primary === null) {
            throw new BundleIntegrityException("Approval {$approval->id} covers an artifact that no longer exists.");
        }
        ReviewBundle::lock($primary);

        return ApplicationPayload::hash(ApplicationPayload::for($primary));
    }

    /**
     * The bundle's transition, under its lock. Null when it was already decided.
     *
     * @param  array{approval_id?: string, approved_version_hash?: ?string}  $decision
     */
    private function decide(string $tenantId, AgentArtifact $found, bool $granted, string $response, array $decision): ?AgentArtifact
    {
        $bundle = ReviewBundle::lock(ReviewBundle::primaryOf($found));
        $artifact = $bundle->firstWhere('id', $found->id);

        if ($artifact === null || in_array($artifact->status, self::DECIDED, true)) {
            return null; // idempotent: the chain and the controller may both report
        }

        // Only the approval that covers these artifacts now may decide them.
        $approvalId = (string) ($decision['approval_id'] ?? '');
        if ($approvalId === '' || $approvalId !== (string) $artifact->approval_id) {
            throw new BundleIntegrityException(sprintf(
                'Approval %s does not cover artifact %s (its approval is %s); nothing was decided.',
                $approvalId === '' ? '(none named)' : $approvalId, $artifact->id, $artifact->approval_id ?? 'none',
            ));
        }
        $approvedHash = $decision['approved_version_hash'] ?? null;
        if ($granted && ! is_string($approvedHash)) {
            throw new BundleIntegrityException("The grant for {$artifact->id} bound no version, so there is no approved content to apply.");
        }

        $edited = false;
        foreach ($bundle as $item) {
            $meta = (array) $item->meta;
            $original = (string) ($meta['original_content'] ?? '');
            if ($original !== '' && $original !== (string) $item->content) {
                $edited = true;
                if (! $this->recordRevision($tenantId, $item, $original, $granted, $response)) {
                    // The edit happened and its size is unknown. Say so on the
                    // artifact, so nothing downstream reads the missing
                    // revision as a clean draft.
                    $item->forceFill(['meta' => ((array) $item->meta) + ['revision_unrecorded' => true]])->save();
                }
            }
        }

        $now = now();
        $ids = $bundle->pluck('id')->all();

        if (! $granted) {
            foreach ($bundle as $item) {
                $item->forceFill([
                    'status' => AgentArtifact::STATUS_REJECTED,
                    'meta' => ((array) $item->meta) + ['rejected_reason' => mb_substr($response, 0, 500), 'rejected_at' => $now->toIso8601String()],
                ])->save();
            }
            $this->bumpCleanDrafts($tenantId, (string) $artifact->skill_slug, reset: true);
            $this->events->append($tenantId, 'agent_artifact', (string) $artifact->id, 'agent.artifact.rejected', [
                'artifact_id' => $artifact->id, 'artifact_ids' => $ids, 'kind' => $artifact->kind, 'run_id' => $artifact->run_id,
                'skill_slug' => $artifact->skill_slug, 'approval_id' => $artifact->approval_id, 'reason' => $response, 'edited' => $edited,
            ], ['runtime' => 'php_skill']);
            $this->idleWorkspace($artifact);

            return $artifact;
        }

        AgentArtifact::forTenant($tenantId)->whereIn('id', $ids)->update([
            'status' => AgentArtifact::STATUS_APPROVED,
            'approved_at' => $now,
            'updated_at' => $now,
        ]);
        $artifact->refresh();

        // Apply exactly the approved version: a sequence applies its steps,
        // standalone drafts each record their application.
        $this->applier->applyApproved($artifact, (string) $approvedHash);

        $this->bumpCleanDrafts($tenantId, (string) $artifact->skill_slug, reset: $edited);
        $this->events->append($tenantId, 'agent_artifact', (string) $artifact->id, 'agent.artifact.approved', [
            'artifact_id' => $artifact->id, 'artifact_ids' => $ids, 'kind' => $artifact->kind, 'run_id' => $artifact->run_id,
            'skill_slug' => $artifact->skill_slug, 'approval_id' => $artifact->approval_id, 'edited' => $edited, 'response' => $response,
        ], ['runtime' => 'php_skill']);
        $this->idleWorkspace($artifact);

        return $artifact;
    }

    /** Whether the revision was recorded. */
    private function recordRevision(string $tenantId, AgentArtifact $item, string $original, bool $granted, string $response): bool
    {
        $recorder = Collaborators::revisionRecorder();
        if ($recorder === null) {
            return false;
        }
        $meta = (array) $item->meta;

        return BestEffort::succeeded(
            fn () => $recorder->record($tenantId, 'agent_artifact', (string) $item->id, $original, (string) $item->content, $meta['edited_by'] ?? null, [
                'kind' => $item->kind,
                'run_id' => $item->run_id,
                'skill_slug' => $item->skill_slug,
                'approval_id' => $item->approval_id,
                'outcome' => $granted ? 'approved' : 'rejected',
                'response' => $response,
            ]),
            'artifact revision could not be recorded',
            ['artifact_id' => $item->id],
        );
    }

    /**
     * Promotion gate counter (plan D5): +1 for a clean approval, 0 on an edit
     * or rejection. The two directions fail differently on purpose: a lost
     * increment under-counts, which is safe; a lost reset lets a streak
     * survive the rejection that should have ended it, so the reset is
     * required and its failure rolls the decision back.
     */
    private function bumpCleanDrafts(string $tenantId, string $skillSlug, bool $reset): void
    {
        if ($skillSlug === '') {
            return;
        }

        $query = TenantSkill::forTenant($tenantId)->where('skill_slug', $skillSlug);
        if ($reset) {
            $query->update(['clean_drafts_count' => 0, 'updated_at' => now()]);
        } else {
            BestEffort::attempt(
                fn () => $query->update(['clean_drafts_count' => DB::raw('clean_drafts_count + 1'), 'updated_at' => now()]),
                'clean_drafts_count increment skipped',
                ['skill' => $skillSlug],
            );
        }

        $this->checkTripwires($tenantId, $skillSlug);
    }

    /**
     * The other half of the ladder.
     *
     * `AgentCircuitBreaker::evaluate()` was written, tested and never called
     * from anywhere in app/ — so a skill could be rejected ten times in a row
     * and keep its autonomy level, while the promotion side of the same ladder
     * worked perfectly. The gate only moves in one direction if nothing
     * evaluates the tripwires, and a ladder you can only climb is not a ladder.
     *
     * This is the right place: the hook already runs on every approval
     * decision and already holds the tenant and the slug. It can never fail
     * the approval — a tripwire that breaks an approval would be worse than
     * one that does not fire.
     */
    private function checkTripwires(string $tenantId, string $skillSlug): void
    {
        $reason = BestEffort::attempt(
            fn () => $this->breaker->evaluate($tenantId, $skillSlug),
            'agent.tripwire.check_failed',
            ['tenant_id' => $tenantId, 'skill' => $skillSlug],
        );
        if ($reason !== null) {
            Log::info('agent.tripwire.demoted', ['tenant_id' => $tenantId, 'skill' => $skillSlug, 'reason' => $reason]);
        }
    }

    /** Workspace back to idle once its review is done. */
    private function idleWorkspace(AgentArtifact $artifact): void
    {
        $workspace = $artifact->workspace_id ? AgentWorkspace::find($artifact->workspace_id) : null;
        if ($workspace === null) {
            return;
        }

        $stillPending = AgentArtifact::forTenant((string) $artifact->tenant_id)
            ->where('workspace_id', $workspace->id)
            ->where('status', AgentArtifact::STATUS_SUBMITTED)
            ->exists();
        if (! $stillPending && $workspace->status === AgentWorkspace::STATUS_NEEDS_REVIEW) {
            $this->workspaces->markStatus($workspace, AgentWorkspace::STATUS_IDLE);
        }
    }

    /** Tell the cockpit — only about committed state. */
    private function broadcast(AgentArtifact $artifact): void
    {
        $run = $artifact->run_id ? AgentRun::find($artifact->run_id) : null;
        if ($run !== null) {
            AgentRunUpdated::safeBroadcast($run);
        }
    }
}
