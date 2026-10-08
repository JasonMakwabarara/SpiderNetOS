<?php

declare(strict_types=1);

namespace App\Services\Tools\Drafts;

use App\Models\AgentArtifact;
use App\Services\Agents\RunContext;
use App\Services\Tools\ToolContract;
use App\Services\Tools\ToolResult;
use Illuminate\Support\Str;

/**
 * Save a multi-step outreach sequence: one `draft_sequence` artifact that
 * owns the structure plus one `draft_email` per step under
 * `<drafts_root>/<skill>/<run>/step-{n}.md`. Variants (a|b) live inside
 * each step's meta; ArtifactApplier keys message_templates
 * `outreach.<campaign>.step{n}.{a|b}` from them.
 */
final class DraftsSaveSequenceTool implements ToolContract
{
    public function name(): string
    {
        return 'drafts.save_sequence';
    }

    public function description(): string
    {
        return 'Save a multi-step email sequence as drafts: one sequence artifact plus one draft email per step (with optional A/B variants).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'campaign' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'channel' => ['type' => 'string', 'enum' => ['email', 'linkedin', 'whatsapp'], 'default' => 'email'],
                'segment' => ['type' => 'string'],
                'steps' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 12,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'n' => ['type' => 'integer', 'minimum' => 1],
                            'subject' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                            'delay_days' => ['type' => 'integer', 'minimum' => 0],
                            'subject_alternatives' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'variants' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'key' => ['type' => 'string', 'pattern' => '^[a-z]$'],
                                        'subject' => ['type' => 'string'],
                                        'body' => ['type' => 'string'],
                                    ],
                                    'required' => ['key', 'body'],
                                ],
                            ],
                        ],
                        'required' => ['body'],
                    ],
                ],
            ],
            'required' => ['campaign', 'steps'],
            'additionalProperties' => true,
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
        $campaign = trim((string) ($params['campaign'] ?? ''));
        $steps = array_values(array_filter((array) ($params['steps'] ?? []), 'is_array'));
        if ($campaign === '') {
            return ToolResult::fail('campaign_required');
        }
        if ($steps === []) {
            return ToolResult::fail('steps_required');
        }
        // limits.max_artifacts counts sequences (primary outputs); the step
        // emails belong to the sequence and never count against it.
        $max = (int) ($ctx->card->limits()['max_artifacts'] ?? 0);
        if ($max > 0 && AgentArtifact::where('run_id', $ctx->run->id)->where('kind', AgentArtifact::KIND_DRAFT_SEQUENCE)->count() >= $max) {
            return ToolResult::fail('max_artifacts_reached', ['limit' => $max]);
        }

        $channel = (string) ($params['channel'] ?? 'email');
        $segment = isset($params['segment']) ? (string) $params['segment'] : null;
        $campaignKey = Str::slug($campaign, '-');

        $sequence = AgentArtifact::create([
            'tenant_id' => $ctx->tenantId,
            'run_id' => $ctx->run->id,
            'workspace_id' => $ctx->run->workspace_id,
            'skill_slug' => $ctx->skillSlug(),
            'kind' => AgentArtifact::KIND_DRAFT_SEQUENCE,
            'path' => DraftsSaveTool::draftPath($ctx, 'sequence.md'),
            'title' => mb_substr("{$campaign} — ".count($steps).'-step '.$channel.' sequence', 0, 255),
            'content' => '',
            'meta' => [],
            'status' => AgentArtifact::STATUS_DRAFT,
        ]);

        $stepMeta = [];
        $emailIds = [];
        $markdown = ["# {$campaign}", '', "Channel: {$channel}".($segment ? " · Segment: {$segment}" : ''), ''];

        foreach ($steps as $i => $step) {
            $n = max(1, (int) ($step['n'] ?? ($i + 1)));
            $subject = trim((string) ($step['subject'] ?? ''));
            $body = trim((string) ($step['body'] ?? ''));
            $variants = self::variants($step, $subject, $body);

            $content = ($subject !== '' ? "Subject: {$subject}\n\n" : '').$body;
            $email = AgentArtifact::create([
                'tenant_id' => $ctx->tenantId,
                'run_id' => $ctx->run->id,
                'workspace_id' => $ctx->run->workspace_id,
                'skill_slug' => $ctx->skillSlug(),
                'kind' => AgentArtifact::KIND_DRAFT_EMAIL,
                'path' => DraftsSaveTool::draftPath($ctx, "step-{$n}.md"),
                'title' => mb_substr($subject !== '' ? "Step {$n}: {$subject}" : "Step {$n}", 0, 255),
                'content' => $content,
                'meta' => [
                    'sequence_id' => $sequence->id,
                    'campaign' => $campaign,
                    'campaign_key' => $campaignKey,
                    'channel' => $channel,
                    'n' => $n,
                    'subject' => $subject,
                    'body' => $body,
                    'delay_days' => isset($step['delay_days']) ? (int) $step['delay_days'] : null,
                    'subject_alternatives' => array_values(array_map('strval', (array) ($step['subject_alternatives'] ?? []))),
                    'variants' => $variants,
                ],
                'status' => AgentArtifact::STATUS_DRAFT,
            ]);
            $emailIds[] = $email->id;

            $stepMeta[] = [
                'n' => $n,
                'artifact_id' => $email->id,
                'path' => $email->path,
                'subject' => $subject,
                'body' => $body,
                'delay_days' => isset($step['delay_days']) ? (int) $step['delay_days'] : null,
                'variants' => $variants,
            ];

            $markdown[] = "## Step {$n}".($subject !== '' ? " — {$subject}" : '');
            $markdown[] = '';
            $markdown[] = $body;
            $markdown[] = '';
        }

        $sequence->forceFill([
            'content' => implode("\n", $markdown),
            'meta' => [
                'campaign' => $campaign,
                'campaign_key' => $campaignKey,
                'channel' => $channel,
                'segment' => $segment,
                'pack_id' => $ctx->card->packId() ?? 'sales-crm',
                'skill_version' => $ctx->card->version(),
                'step_count' => count($stepMeta),
                'steps' => $stepMeta,
                'artifact_ids' => $emailIds,
            ],
        ])->save();

        return ToolResult::ok([
            'sequence_id' => $sequence->id,
            'artifact_ids' => $emailIds,
            'paths' => array_map(static fn (array $s) => $s['path'], $stepMeta),
            'campaign_key' => $campaignKey,
            'step_count' => count($stepMeta),
        ]);
    }

    /**
     * Variants for a step: the authored `variants[]` (keyed a, b, …), or a
     * single `a` from the step's subject/body.
     *
     * @param  array<string, mixed>  $step
     * @return list<array{key: string, subject: string, body: string}>
     */
    private static function variants(array $step, string $subject, string $body): array
    {
        $out = [];
        foreach ((array) ($step['variants'] ?? []) as $i => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $key = strtolower(trim((string) ($variant['key'] ?? chr(ord('a') + $i))));
            $out[] = [
                'key' => preg_match('/^[a-z]$/', $key) ? $key : chr(ord('a') + count($out)),
                'subject' => trim((string) ($variant['subject'] ?? $subject)),
                'body' => trim((string) ($variant['body'] ?? $body)),
            ];
        }

        return $out !== [] ? $out : [['key' => 'a', 'subject' => $subject, 'body' => $body]];
    }
}
