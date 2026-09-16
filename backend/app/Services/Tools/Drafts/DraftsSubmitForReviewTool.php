<?php

declare(strict_types=1);

namespace App\Services\Tools\Drafts;

use App\Models\AgentArtifact;
use App\Models\AgentWorkspace;
use App\Services\Agents\RunContext;
use App\Services\Agents\WorkspaceProvisioner;
use App\Services\ApprovalEngine;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use Illuminate\Support\Collection;

/**
 * Turn a draft artifact into ONE `agent_artifact` approval. For a
 * draft_sequence the approval covers the sequence and every step email
 * (they share the approval_id); ApprovalEngine's policy chain applies when
 * a tenant configured one, else a single-stage approval is created.
 * Asking for a human's review is itself never gated by the ladder.
 */
final class DraftsSubmitForReviewTool implements ToolContract
{
    public function __construct(
        private readonly ApprovalEngine $approvals,
        private readonly WorkspaceProvisioner $workspaces,
    ) {}

    public function name(): string
    {
        return 'drafts.submit_for_review';
    }

    public function description(): string
    {
        return 'Submit a saved draft (or a whole sequence) for human approval. Creates exactly one approval and returns its id.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'artifact_id' => ['type' => 'string', 'description' => 'The draft or sequence artifact to submit'],
                'reason' => ['type' => 'string', 'maxLength' => 500, 'description' => 'One line for the approver'],
            ],
            'required' => ['artifact_id'],
            'additionalProperties' => false,
        ];
    }

    public function risk(): string
    {
        return self::RISK_DRAFT;
    }

    public function requiresConnector(): ?string
    {
        return null;
    }

    public function execute(RunContext $ctx, array $params): ToolResult
    {
        $artifact = AgentArtifact::forTenant($ctx->tenantId)->find((string) ($params['artifact_id'] ?? ''));
        if ($artifact === null) {
            return ToolResult::fail('artifact_not_found');
        }
        if ($artifact->approval_id !== null) {
            // Idempotent: a second submit returns the existing approval.
            return ToolResult::ok(['approval_id' => $artifact->approval_id, 'artifact_id' => $artifact->id, 'already_submitted' => true]);
        }
        if ($artifact->status !== AgentArtifact::STATUS_DRAFT) {
            return ToolResult::fail('artifact_not_draft', ['status' => $artifact->status]);
        }

        $bundle = self::bundle($artifact);
        $meta = (array) $artifact->meta;
        $reason = trim((string) ($params['reason'] ?? ''));
        if ($reason === '') {
            $reason = $artifact->kind === AgentArtifact::KIND_DRAFT_SEQUENCE
                ? sprintf('%s drafted a %d-step %s sequence for "%s"', $ctx->card->displayName(), (int) ($meta['step_count'] ?? $bundle->count() - 1), (string) ($meta['channel'] ?? 'email'), (string) ($meta['campaign'] ?? $artifact->title))
                : sprintf('%s drafted: %s', $ctx->card->displayName(), (string) $artifact->title);
        }

        $approval = $this->approvals->createChainedApproval(
            $ctx->tenantId,
            $ctx->agentId() ?? $ctx->tenantId,
            'agent_artifact',
            'agent_artifact',
            (string) $artifact->id,
            mb_substr($reason, 0, 500),
            [
                'action' => 'submit',
                'attributes' => [
                    'kind' => $artifact->kind,
                    'skill_slug' => $ctx->skillSlug(),
                    'steps' => (int) ($meta['step_count'] ?? 1),
                    'risk' => 'draft',
                    'autonomy_level' => $ctx->autonomy(),
                ],
                'run_id' => $ctx->run->id,
                'workspace_id' => $ctx->run->workspace_id,
                'skill_slug' => $ctx->skillSlug(),
                'kind' => $artifact->kind,
                'artifact_id' => $artifact->id,
                'artifact_ids' => $bundle->pluck('id')->values()->all(),
                'campaign' => $meta['campaign'] ?? null,
                'channel' => $meta['channel'] ?? null,
                'title' => $artifact->title,
                'preview' => mb_substr((string) $artifact->content, 0, 1200),
                'risk' => 'draft',
            ],
        );
        $approvalId = (string) ($approval['id'] ?? '');

        AgentArtifact::whereIn('id', $bundle->pluck('id')->all())->update([
            'status' => AgentArtifact::STATUS_SUBMITTED,
            'approval_id' => $approvalId,
            'submitted_at' => now(),
            'updated_at' => now(),
        ]);

        $ctx->trace->event('agent.artifact.submitted', [
            'artifact_id' => $artifact->id,
            'artifact_ids' => $bundle->pluck('id')->values()->all(),
            'kind' => $artifact->kind,
            'approval_id' => $approvalId,
        ]);
        $this->workspaces->markStatus($ctx->workspace, AgentWorkspace::STATUS_NEEDS_REVIEW, (string) $ctx->run->id);

        return ToolResult::ok([
            'approval_id' => $approvalId,
            'artifact_id' => $artifact->id,
            'artifact_ids' => $bundle->pluck('id')->values()->all(),
            'kind' => $artifact->kind,
        ]);
    }

    /**
     * The artifact plus, for a sequence, every step email that belongs to it.
     *
     * @return Collection<int, AgentArtifact>
     */
    public static function bundle(AgentArtifact $artifact): Collection
    {
        $bundle = collect([$artifact]);
        if ($artifact->kind !== AgentArtifact::KIND_DRAFT_SEQUENCE) {
            return $bundle;
        }

        $ids = array_values(array_map('strval', (array) (((array) $artifact->meta)['artifact_ids'] ?? [])));
        $children = $ids === []
            ? collect()
            : AgentArtifact::forTenant((string) $artifact->tenant_id)->whereIn('id', $ids)->get();

        return $bundle->merge($children)->unique('id')->values();
    }
}
