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
 */
final class AgentArtifactApprovals
{
    public function __construct(
        private readonly ArtifactApplier $applier,
        private readonly EventStore $events,
        private readonly WorkspaceProvisioner $workspaces,
    ) {}

    public function onApprovalResolved(string $tenantId, string $resourceId, bool $granted, string $response = ''): void
    {
        // id and approval_id are uuid columns; a non-uuid resource id can only be "unknown".
        $artifact = Str::isUuid($resourceId)
            ? (AgentArtifact::forTenant($tenantId)->find($resourceId)
                ?? AgentArtifact::forTenant($tenantId)->where('approval_id', $resourceId)->orderByRaw("case when kind = 'draft_sequence' then 0 else 1 end")->first())
            : null;

        if ($artifact === null) {
            Log::warning('agent_artifact approval resolved for an unknown artifact', ['tenant_id' => $tenantId, 'resource_id' => $resourceId]);

            return;
        }
        if (in_array($artifact->status, [AgentArtifact::STATUS_APPROVED, AgentArtifact::STATUS_REJECTED, AgentArtifact::STATUS_APPLIED], true)) {
            return; // idempotent: the chain and the controller may both report
        }

        $bundle = DraftsSubmitForReviewTool::bundle($artifact);
        if ($artifact->approval_id !== null) {
            $siblings = AgentArtifact::forTenant($tenantId)->where('approval_id', $artifact->approval_id)->get();
            $bundle = $bundle->merge($siblings)->unique('id')->values();
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
            $this->settle($artifact);

            return;
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
        $this->settle($artifact);
    }

    private function recordRevision(string $tenantId, AgentArtifact $item, string $original, bool $granted, string $response): void
    {
        $recorder = Collaborators::revisionRecorder();
        if ($recorder === null) {
            return;
        }
        $meta = (array) $item->meta;

        try {
            $recorder->record($tenantId, 'agent_artifact', (string) $item->id, $original, (string) $item->content, $meta['edited_by'] ?? null, [
                'kind' => $item->kind,
                'run_id' => $item->run_id,
                'skill_slug' => $item->skill_slug,
                'approval_id' => $item->approval_id,
                'outcome' => $granted ? 'approved' : 'rejected',
                'response' => $response,
            ]);
        } catch (\Throwable $e) {
            Log::warning('artifact revision could not be recorded', ['artifact_id' => $item->id, 'error' => $e->getMessage()]);
        }
    }

    /** Promotion gate counter (plan D5): +1 for a clean approval, 0 on an edit or rejection. */
    private function bumpCleanDrafts(string $tenantId, string $skillSlug, bool $reset): void
    {
        if ($skillSlug === '') {
            return;
        }
        try {
            $query = TenantSkill::forTenant($tenantId)->where('skill_slug', $skillSlug);
            $reset
                ? $query->update(['clean_drafts_count' => 0, 'updated_at' => now()])
                : $query->update(['clean_drafts_count' => DB::raw('clean_drafts_count + 1'), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::debug('clean_drafts_count update skipped', ['skill' => $skillSlug, 'error' => $e->getMessage()]);
        }
    }

    /** Workspace back to idle once its review is done; tell the cockpit. */
    private function settle(AgentArtifact $artifact): void
    {
        $run = $artifact->run_id ? AgentRun::find($artifact->run_id) : null;
        $workspace = $artifact->workspace_id ? AgentWorkspace::find($artifact->workspace_id) : null;

        if ($workspace !== null) {
            $stillPending = AgentArtifact::forTenant((string) $artifact->tenant_id)
                ->where('workspace_id', $workspace->id)
                ->where('status', AgentArtifact::STATUS_SUBMITTED)
                ->exists();
            if (! $stillPending && $workspace->status === AgentWorkspace::STATUS_NEEDS_REVIEW) {
                $this->workspaces->markStatus($workspace, AgentWorkspace::STATUS_IDLE);
            }
        }

        if ($run !== null) {
            AgentRunUpdated::safeBroadcast($run);
        }
    }
}
