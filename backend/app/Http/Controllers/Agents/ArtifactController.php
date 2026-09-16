<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Services\Agents\ArtifactApplier;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\Agents\RunContextFactory;
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

    public function index(Request $request): JsonResponse
    {
        $query = AgentArtifact::forTenant($this->tenantId($request))->orderByDesc('created_at');

        foreach (['run_id', 'kind', 'status', 'workspace_id', 'skill_slug'] as $filter) {
            $value = (string) $request->query($filter, '');
            if ($value !== '') {
                $query->whereIn($filter, array_filter(array_map('trim', explode(',', $value))));
            }
        }

        $page = $query->paginate(max(1, min(100, (int) $request->query('per_page', 20))));
        $page->getCollection()->transform(fn (AgentArtifact $a) => $this->artifactPayload($a, false));

        return response()->json($page);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $artifact = AgentArtifact::forTenant($this->tenantId($request))->find($id);
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
        ]);

        $artifact = AgentArtifact::forTenant($this->tenantId($request))->find($id);
        if ($artifact === null) {
            return response()->json(['error' => 'artifact_not_found'], 404);
        }
        if (in_array($artifact->status, self::FROZEN, true)) {
            return response()->json(['error' => 'artifact_frozen', 'status' => $artifact->status, 'message' => 'Resolved artifacts cannot be edited.'], 409);
        }

        $meta = (array) ($artifact->meta ?? []);
        $original = (string) ($meta['original_content'] ?? $artifact->content);
        $userId = $request->user()?->id ? (string) $request->user()->id : null;
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
            $incoming = (array) $validated['meta'];
            unset($incoming['original_content'], $incoming['artifact_ids'], $incoming['sequence_id']);
            $meta = array_replace($meta, $incoming);
            $changed = true;
        }
        if (! empty($validated['title'])) {
            $artifact->title = (string) $validated['title'];
            $changed = true;
        }

        if ($changed) {
            $artifact->meta = $meta;
            $artifact->save();

            if ($artifact->approval_id !== null) {
                $this->refreshApprovalContext($artifact);
            }
        }

        return response()->json(['data' => $this->artifactPayload($artifact) + ['original_content' => $original]]);
    }

    public function submit(Request $request, string $id, RunContextFactory $contexts, DraftsSubmitForReviewTool $tool): JsonResponse
    {
        $artifact = AgentArtifact::forTenant($this->tenantId($request))->find($id);
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

    public function apply(Request $request, string $id, ArtifactApplier $applier): JsonResponse
    {
        $artifact = AgentArtifact::forTenant($this->tenantId($request))->find($id);
        if ($artifact === null) {
            return response()->json(['error' => 'artifact_not_found'], 404);
        }
        if ($artifact->status === AgentArtifact::STATUS_APPLIED) {
            return response()->json(['data' => $this->artifactPayload($artifact)]);
        }
        if ($artifact->status !== AgentArtifact::STATUS_APPROVED) {
            return response()->json(['error' => 'artifact_not_approved', 'status' => $artifact->status, 'message' => 'Approve the artifact before applying it.'], 409);
        }

        $applier->apply($artifact);

        return response()->json(['data' => $this->artifactPayload($artifact->refresh())]);
    }

    /** Keep the pending approval's preview in step with the edited draft (RecruiterBot PATCH pattern). */
    private function refreshApprovalContext(AgentArtifact $artifact): void
    {
        $row = DB::table('approvals')->where('id', $artifact->approval_id)->where('status', 'pending')->first(['id', 'context']);
        if ($row === null) {
            return;
        }
        $context = json_decode((string) $row->context, true);
        $context = is_array($context) ? $context : [];
        if (($context['artifact_id'] ?? null) === $artifact->id) {
            $context['preview'] = mb_substr((string) $artifact->content, 0, 1200);
            $context['title'] = $artifact->title;
        }
        $context['edited'] = true;
        $context['edited_artifact_ids'] = array_values(array_unique(array_merge((array) ($context['edited_artifact_ids'] ?? []), [$artifact->id])));
        DB::table('approvals')->where('id', $row->id)->update(['context' => json_encode($context), 'updated_at' => now()]);
    }
}
