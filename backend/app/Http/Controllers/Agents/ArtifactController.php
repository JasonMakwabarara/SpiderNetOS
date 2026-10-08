<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Services\Agents\ApplicationPayload;
use App\Services\Agents\ArtifactApplier;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use App\Services\Agents\ReviewBundle;
use App\Services\Agents\RunContextFactory;
use App\Services\ApprovalEngine;
use App\Services\Tools\Drafts\DraftsSubmitForReviewTool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/artifacts — the drafts a run produced. PATCH keeps the model's
 * original next to the human edit (D8 #1: every edit is a lesson);
 * submit creates the one approval; apply runs ArtifactApplier on an
 * approved artifact.
 */
class ArtifactController extends AgentsController
{
    private const FROZEN = [AgentArtifact::STATUS_APPROVED, AgentArtifact::STATUS_APPLIED, AgentArtifact::STATUS_REJECTED];

    /** Meta that defines a bundle and its destination (what ApplicationPayload reads), never content. */
    private const STRUCTURAL_META = ['steps', 'artifact_ids', 'sequence_id', 'original_content', 'campaign', 'campaign_key', 'channel', 'pack_id'];

    public function index(Request $request): JsonResponse
    {
        $query = AgentArtifact::forTenant($this->tenantId($request))->orderByDesc('created_at');

        // run_id / workspace_id are uuid columns: a non-uuid filter matches nothing rather than erroring on Postgres.
        foreach (['run_id' => true, 'kind' => false, 'status' => false, 'workspace_id' => true, 'skill_slug' => false] as $filter => $uuid) {
            if ((string) $request->query($filter, '') !== '') {
                $query->whereIn($filter, $this->filterValues($request, $filter, $uuid));
            }
        }

        $page = $query->paginate(max(1, min(100, (int) $request->query('per_page', 20))));
        $page->getCollection()->transform(fn (AgentArtifact $a) => $this->artifactPayload($a, false));

        return response()->json($page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $artifact = $this->findArtifact($request, $id);
        if ($artifact === null) {
            return response()->json(['error' => 'artifact_not_found'], 404);
        }

        return response()->json(['data' => $this->artifactPayload($artifact)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'nullable|string|max:200000',
            'meta' => 'nullable|array',
            'title' => 'nullable|string|max:255',
            // The version of the review this edit started from; required while
            // the artifact is under review (see below).
            'expected_version' => 'nullable|string|max:80',
        ]);

        $artifact = $this->findArtifact($request, $id);
        if ($artifact === null) {
            return response()->json(['error' => 'artifact_not_found'], 404);
        }
        if (in_array($artifact->status, self::FROZEN, true)) {
            return response()->json(['error' => 'artifact_frozen', 'status' => $artifact->status, 'message' => 'Resolved artifacts cannot be edited.'], 409);
        }
        // Editing content under review changes what the approver approves,
        // so only someone who may approve it may edit it. Drafts stay with
        // any member.
        if ($artifact->status === AgentArtifact::STATUS_SUBMITTED && ! $request->user()?->can_do('approvals.decide')) {
            return response()->json(['error' => 'forbidden', 'reason' => 'approver_capability_required', 'message' => 'Only an approver may edit an artifact under review.'], 403);
        }
        // Where the bundle goes and what it is made of are not edits to its
        // content. They were silently stripped for three keys and accepted for
        // the rest, so a PATCH could re-point a step at another artifact.
        $structural = array_values(array_intersect(array_keys((array) ($validated['meta'] ?? [])), self::STRUCTURAL_META));
        if ($structural !== []) {
            return response()->json(['error' => 'structural_meta', 'keys' => $structural, 'message' => 'These fields define the bundle and its destination; they cannot be edited.'], 422);
        }

        $userId = $request->user()?->id ? (string) $request->user()->id : null;

        // One transaction over the locked bundle — the same lock, in the same
        // order, that the decision takes — so an edit and a decision cannot
        // interleave: whichever commits second sees the other.
        return DB::transaction(function () use ($artifact, $validated, $userId): JsonResponse {
            $artifact = ReviewBundle::lock(ReviewBundle::primaryOf($artifact))->firstWhere('id', $artifact->id) ?? $artifact->refresh();
            if (in_array($artifact->status, self::FROZEN, true)) {
                return response()->json(['error' => 'artifact_frozen', 'status' => $artifact->status, 'message' => 'Resolved artifacts cannot be edited.'], 409);
            }
            if ($artifact->approval_id !== null) {
                $review = DB::table('approvals')->where('id', $artifact->approval_id)->first(['status', 'current_step', 'version_hash']);
                if ($review?->status !== 'pending') {
                    // Decided, and not yet applied: the version it approved is
                    // the one that must be applied.
                    return response()->json(['error' => 'approval_decided', 'status' => $review?->status, 'message' => 'This review has been decided; its content can no longer change.'], 409);
                }
                // Once any step of a chain has approved, the content is what
                // that reviewer approved. An edit would leave the chain's
                // remaining steps approving something the earlier ones never
                // saw, so the only way to change it is to reject and resubmit.
                if ($review->current_step !== null && DB::table('approval_steps')->where('approval_id', $artifact->approval_id)->where('status', 'approved')->exists()) {
                    return response()->json(['error' => 'review_in_progress', 'message' => 'A reviewer has already approved this version. Reject it and submit again to change it.'], 409);
                }
                // Two editors who loaded the same version must not overwrite
                // each other: the lock orders their saves, and this makes the
                // second one notice that it started from content that is gone.
                $expected = (string) ($validated['expected_version'] ?? '');
                if ($expected === '') {
                    return response()->json(['error' => 'edit_version_missing', 'message' => 'An edit under review needs the version it started from (expected_version).'], 409);
                }
                if ($review->version_hash === null || ! hash_equals((string) $review->version_hash, $expected)) {
                    return response()->json(['error' => 'edit_stale', 'message' => 'This review changed after you loaded it. Reload it and edit the current version.'], 409);
                }
            }

            $meta = (array) ($artifact->meta ?? []);
            $original = (string) ($meta['original_content'] ?? $artifact->content);
            $changed = false;

            if (array_key_exists('content', $validated) && $validated['content'] !== null && $validated['content'] !== (string) $artifact->content) {
                if (! isset($meta['original_content'])) {
                    $meta['original_content'] = (string) $artifact->content;
                }
                $meta['edited_at'] = now()->toIso8601String();
                $meta['edited_by'] = $userId;
                $meta['edit_count'] = (int) ($meta['edit_count'] ?? 0) + 1;
                $artifact->content = (string) $validated['content'];
                $changed = true;
            }
            if (! empty($validated['meta'])) {
                $meta = array_replace($meta, (array) $validated['meta']);
                $changed = true;
            }
            if (! empty($validated['title'])) {
                $artifact->title = (string) $validated['title'];
                $changed = true;
            }

            $version = null;
            if ($changed) {
                $artifact->meta = $meta;
                $artifact->save();

                if ($artifact->approval_id !== null) {
                    $version = $this->rebindApproval($artifact);
                }
            }

            return response()->json(['data' => $this->artifactPayload($artifact) + [
                'original_content' => $original,
                // The version the approval now covers. The previous one is
                // superseded: an approval presenting it is refused as stale.
                'approval_version_hash' => $version ?? ($artifact->approval_id !== null ? DB::table('approvals')->where('id', $artifact->approval_id)->value('version_hash') : null),
            ]]);
        });
    }

    public function submit(Request $request, string $id, RunContextFactory $contexts, DraftsSubmitForReviewTool $tool): JsonResponse
    {
        $artifact = $this->findArtifact($request, $id);
        if ($artifact === null) {
            return response()->json(['error' => 'artifact_not_found'], 404);
        }
        if ($artifact->approval_id !== null) {
            return response()->json(['data' => ['approval_id' => $artifact->approval_id, 'artifact_id' => $artifact->id, 'status' => $artifact->status]]);
        }
        if ($artifact->status !== AgentArtifact::STATUS_DRAFT) {
            return response()->json(['error' => 'artifact_not_draft', 'status' => $artifact->status], 409);
        }

        $run = $artifact->run_id ? AgentRun::forTenant($this->tenantId($request))->find($artifact->run_id) : null;
        if ($run === null) {
            if ($artifact->kind === AgentArtifact::KIND_RESEARCH_BRIEF) {
                return $this->submitResearchBrief($request, $artifact, app(ApprovalEngine::class));
            }

            return response()->json(['error' => 'artifact_has_no_run', 'message' => 'Only artifacts produced by a run can be submitted.'], 422);
        }

        try {
            $ctx = $contexts->forRun($run, null, rebuildSnapshot: true);
            $result = $tool->execute($ctx, ['artifact_id' => $artifact->id, 'reason' => (string) $request->input('reason', '')]);
        } catch (AgentRuntimeException $e) {
            return $this->fail($e);
        }

        if (! $result->success) {
            return response()->json(['error' => (string) $result->error] + $result->data, 422);
        }

        return response()->json(['data' => $result->data + ['status' => AgentArtifact::STATUS_SUBMITTED]]);
    }

    /**
     * An imported research brief has no agent run. It still becomes one
     * agent_artifact approval, the same resource the run-backed submit uses.
     * Approving it does not publish the brief or fetch its sources.
     */
    private function submitResearchBrief(Request $request, AgentArtifact $artifact, ApprovalEngine $approvals): JsonResponse
    {
        try {
            $payload = ApplicationPayload::for($artifact);
        } catch (BundleIntegrityException $e) {
            return response()->json(['error' => 'bundle_integrity', 'message' => $e->getMessage()], 422);
        }

        $reason = trim((string) $request->input('reason', ''));
        if ($reason === '') {
            $reason = 'Research brief for review: '.(string) $artifact->title;
        }

        $approval = $approvals->createChainedApproval(
            (string) $artifact->tenant_id,
            (string) ($request->user()?->id ?? $artifact->tenant_id),
            'agent_artifact',
            'agent_artifact',
            (string) $artifact->id,
            mb_substr($reason, 0, 500),
            [
                'action' => 'submit',
                'attributes' => [
                    'kind' => $artifact->kind,
                    'steps' => 1,
                    'risk' => 'draft',
                ],
                'kind' => $artifact->kind,
                'artifact_id' => $artifact->id,
                'artifact_ids' => [(string) $artifact->id],
                'title' => $artifact->title,
                'preview' => mb_substr((string) $artifact->content, 0, 1200),
                'payload' => $payload,
                'risk' => 'draft',
            ],
        );
        $approvalId = (string) ($approval['id'] ?? '');

        DB::table('approvals')->where('id', $approvalId)->update([
            'version_hash' => ApplicationPayload::hash($payload),
        ]);

        $artifact->forceFill([
            'status' => AgentArtifact::STATUS_SUBMITTED,
            'approval_id' => $approvalId,
            'submitted_at' => now(),
        ])->save();

        return response()->json(['data' => [
            'approval_id' => $approvalId,
            'artifact_id' => $artifact->id,
            'status' => AgentArtifact::STATUS_SUBMITTED,
        ]]);
    }

    public function apply(Request $request, string $id, ArtifactApplier $applier): JsonResponse
    {
        $artifact = $this->findArtifact($request, $id);
        if ($artifact === null) {
            return response()->json(['error' => 'artifact_not_found'], 404);
        }
        if ($artifact->status === AgentArtifact::STATUS_APPLIED) {
            return response()->json(['data' => $this->artifactPayload($artifact)]);
        }
        if ($artifact->status !== AgentArtifact::STATUS_APPROVED) {
            return response()->json(['error' => 'artifact_not_approved', 'status' => $artifact->status, 'message' => 'Approve the artifact before applying it.'], 409);
        }

        // Apply only the version the decision bound, and apply the whole
        // bundle the approval covers — never a member on its own.
        $approval = DB::table('approvals')->where('id', $artifact->approval_id)->where('tenant_id', $this->tenantId($request))->first(['resource_id', 'status', 'approved_version_hash']);
        if ($approval === null || $approval->status !== 'approved' || $approval->approved_version_hash === null) {
            return response()->json(['error' => 'approval_not_bound', 'message' => 'No approval bound a version of this artifact, so there is no approved content to apply.'], 409);
        }
        $primary = AgentArtifact::forTenant($this->tenantId($request))->find($approval->resource_id);
        if ($primary === null) {
            return response()->json(['error' => 'artifact_not_found'], 404);
        }

        try {
            DB::transaction(function () use ($applier, $primary, $approval): void {
                $locked = ReviewBundle::lock($primary)->firstWhere('id', $primary->id) ?? $primary;
                $applier->applyApproved($locked, (string) $approval->approved_version_hash);
            });
        } catch (BundleIntegrityException $e) {
            return response()->json(['error' => 'bundle_integrity', 'message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $this->artifactPayload($artifact->refresh())]);
    }

    /**
     * An edit while pending supersedes the version the approval covered.
     * Re-hash what approving would now apply, bind the approval to it, and
     * show that payload — the one the hash covers and the applier writes.
     * Called under the bundle's lock.
     */
    private function rebindApproval(AgentArtifact $artifact): string
    {
        $row = DB::table('approvals')->where('id', $artifact->approval_id)->first(['id', 'context', 'resource_id']);
        $primary = AgentArtifact::forTenant((string) $artifact->tenant_id)->findOrFail($row->resource_id);
        $payload = ApplicationPayload::for($primary);
        $version = ApplicationPayload::hash($payload);

        $context = json_decode((string) $row->context, true);
        $context = is_array($context) ? $context : [];
        if (($context['artifact_id'] ?? null) === $artifact->id) {
            $context['preview'] = mb_substr((string) $artifact->content, 0, 1200);
            $context['title'] = $artifact->title;
        }
        $context['edited'] = true;
        $context['edited_artifact_ids'] = array_values(array_unique(array_merge((array) ($context['edited_artifact_ids'] ?? []), [$artifact->id])));
        $context['payload'] = $payload;

        DB::table('approvals')->where('id', $row->id)->update(['context' => json_encode($context), 'version_hash' => $version, 'updated_at' => now()]);

        return $version;
    }
}
