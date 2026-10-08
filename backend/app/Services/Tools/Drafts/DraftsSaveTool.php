<?php

declare(strict_types=1);

namespace App\Services\Tools\Drafts;

use App\Models\AgentArtifact;
use App\Services\Agents\RunContext;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use Illuminate\Support\Str;

/**
 * Save one draft artifact into the agent's workspace drafts folder. Draft
 * only: nothing is sent; `drafts.submit_for_review` turns it into an
 * approval and ArtifactApplier applies it once a human says yes.
 */
final class DraftsSaveTool implements ToolContract
{
    public function name(): string
    {
        return 'drafts.save';
    }

    public function description(): string
    {
        return 'Save a draft (email, reply, caption, report, note…) into the workspace drafts folder. Returns the artifact id and path.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => AgentArtifact::KINDS],
                'title' => ['type' => 'string', 'maxLength' => 255],
                'content' => ['type' => 'string', 'description' => 'Markdown body of the draft'],
                'meta' => ['type' => 'object', 'description' => 'Kind-specific structure (subject, to, slots…)', 'additionalProperties' => true],
                'path' => ['type' => 'string', 'description' => 'Optional file name inside the drafts folder'],
            ],
            'required' => ['kind', 'content'],
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
        $kind = (string) ($params['kind'] ?? AgentArtifact::KIND_NOTE);
        if (! in_array($kind, AgentArtifact::KINDS, true)) {
            return ToolResult::fail('invalid_kind', ['allowed' => AgentArtifact::KINDS]);
        }
        $content = (string) ($params['content'] ?? '');
        if (trim($content) === '') {
            return ToolResult::fail('empty_content');
        }

        // limits.max_artifacts caps primary outputs of one kind per run (a
        // sequence's step emails never count against it).
        $max = (int) ($ctx->card->limits()['max_artifacts'] ?? 0);
        if ($max > 0 && AgentArtifact::where('run_id', $ctx->run->id)->where('kind', $kind)->count() >= $max) {
            return ToolResult::fail('max_artifacts_reached', ['limit' => $max, 'kind' => $kind]);
        }

        $title = trim((string) ($params['title'] ?? '')) ?: Str::headline($kind);
        $file = trim((string) ($params['path'] ?? '')) ?: Str::slug(mb_substr($title, 0, 60)).'.md';
        $file = basename(str_replace('..', '', $file));
        $path = self::draftPath($ctx, $file);

        $artifact = AgentArtifact::create([
            'tenant_id' => $ctx->tenantId,
            'run_id' => $ctx->run->id,
            'workspace_id' => $ctx->run->workspace_id,
            'skill_slug' => $ctx->skillSlug(),
            'kind' => $kind,
            'path' => $path,
            'title' => mb_substr($title, 0, 255),
            'content' => $content,
            'meta' => (array) ($params['meta'] ?? []),
            'status' => AgentArtifact::STATUS_DRAFT,
        ]);

        return ToolResult::ok(['artifact_id' => $artifact->id, 'path' => $path, 'kind' => $kind]);
    }

    /** `<drafts_root>/<skill>/<run>/<file>` — one folder per run inside the workspace drafts root. */
    public static function draftPath(RunContext $ctx, string $file): string
    {
        return sprintf('%s/%s/%s/%s', rtrim($ctx->draftsRoot(), '/'), $ctx->skillSlug(), $ctx->run->id, $file);
    }
}
