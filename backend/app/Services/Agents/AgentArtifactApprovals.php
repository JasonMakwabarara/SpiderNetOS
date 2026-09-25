<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Events\AgentRunUpdated;
use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\TenantSkill;
use App\Services\EventStore;
use App\Services\Tools\Drafts\DraftsSubmitForReviewTool;
use Illuminate\Support\Collection;
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
 * bundle already decided and does nothing. Best-effort writes run in
 * savepoints (BestEffort) so a failed one cannot abort the transaction on
 * Postgres, and the broadcast waits for commit.
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

    public function onApprovalResolved(string $tenantId, string $resourceId, bool $granted, string $response = ''): void
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

        $decided = DB::transaction(fn (): ?AgentArtifact => $this->decide($tenantId, $found, $granted, $response));

        if ($decided !== null) {
            // Runs now when there is no enclosing transaction, or when the
            // chain path's enclosing one commits — never on rolled-back state.
            DB::afterCommit(fn () => $this->broadcast($decided));
        }
    }

    /** The bundle's transition, under its lock. Null when it was already decided. */
    private function decide(string $tenantId, AgentArtifact $found, bool $granted, string $response): ?AgentArtifact
    {
        $bundle = $this->lockBundle($tenantId, $found);
        $artifact = $bundle->firstWhere('id', $found->id);

        if ($artifact === null || in_array($artifact->status, self::DECIDED, true)) {
            return null; // idempotent: the chain and the controller may both report
        }

        $edited = false;
        foreach ($bundle as $item) {
            $meta = (array) $item->meta;
            $original = (string) ($meta['original_content'] ?? '');
            if ($original !== '' && $original !== (string) $item->content) {
                $edited = true;
                $this->recordRevision($tenantId, $item, $original, $granted, $response);
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

        // Apply: a sequence applies its children; standalone drafts apply one by one.
        if ($artifact->kind === AgentArtifact::KIND_DRAFT_SEQUENCE) {
            $this->applier->apply($artifact);
        } else {
            foreach ($bundle as $item) {
                $this->applier->apply($item->refresh());
            }
        }

        $this->bumpCleanDrafts($tenantId, (string) $artifact->skill_slug, reset: $edited);
        $this->events->append($tenantId, 'agent_artifact', (string) $artifact->id, 'agent.artifact.approved', [
            'artifact_id' => $artifact->id, 'artifact_ids' => $ids, 'kind' => $artifact->kind, 'run_id' => $artifact->run_id,
            'skill_slug' => $artifact->skill_slug, 'approval_id' => $artifact->approval_id, 'edited' => $edited, 'response' => $response,
        ], ['runtime' => 'php_skill']);
        $this->idleWorkspace($artifact);

        return $artifact;
    }

    /**
     * Every artifact the decision covers, locked in id order — one order for
     * every resolver, so two resolutions of overlapping bundles queue rather
     * than deadlock. The ids come from an unlocked read; the rows returned,
     * and the statuses decided on, come from the lock.
     *
     * @return Collection<int, AgentArtifact>
     */
    private function lockBundle(string $tenantId, AgentArtifact $artifact): Collection
    {
        $ids = DraftsSubmitForReviewTool::bundle($artifact)->pluck('id');
        if ($artifact->approval_id !== null) {
            $ids = $ids->merge(AgentArtifact::forTenant($tenantId)->where('approval_id', $artifact->approval_id)->pluck('id'));
        }

        return AgentArtifact::forTenant($tenantId)
            ->whereIn('id', $ids->unique()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function recordRevision(string $tenantId, AgentArtifact $item, string $original, bool $granted, string $response): void
    {
        $recorder = Collaborators::revisionRecorder();
        if ($recorder === null) {
            return;
        }
        $meta = (array) $item->meta;

        BestEffort::attempt(
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

    /** Promotion gate counter (plan D5): +1 for a clean approval, 0 on an edit or rejection. */
    private function bumpCleanDrafts(string $tenantId, string $skillSlug, bool $reset): void
    {
        if ($skillSlug === '') {
            return;
        }

        BestEffort::attempt(
            function () use ($tenantId, $skillSlug, $reset): void {
                $query = TenantSkill::forTenant($tenantId)->where('skill_slug', $skillSlug);
                $reset
                    ? $query->update(['clean_drafts_count' => 0, 'updated_at' => now()])
                    : $query->update(['clean_drafts_count' => DB::raw('clean_drafts_count + 1'), 'updated_at' => now()]);
            },
            'clean_drafts_count update skipped',
            ['skill' => $skillSlug],
            'debug',
        );

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
