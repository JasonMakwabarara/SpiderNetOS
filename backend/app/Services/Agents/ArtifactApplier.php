<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentArtifact;
use App\Models\BusinessAsset;
use App\Models\MessageTemplate;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Turns an approved artifact into the real thing (plan D3). PR 1 applies
 * `draft_sequence`: message_templates upserted per step and variant, keyed
 * `outreach.<campaign>.step{n}.{a|b}` (the FunnelSetupService::activate()
 * pattern), one business_assets row of type message_template, and the
 * approved sequence projected into offer/approved-sequences.md through
 * BrainStore::upsertSection (source `agent`) so later runs vary within the
 * approved frame. Other kinds only record an `applied_ref` for now
 * (draft_reply → MessageDispatchService, meeting_proposal → calendar.book
 * and note → brain arrive with their tools in PR 2).
 */
final class ArtifactApplier
{
    public function __construct(private readonly EventStore $events) {}

    public function apply(AgentArtifact $artifact): void
    {
        if ($artifact->isApplied()) {
            return;
        }

        $ref = match ($artifact->kind) {
            AgentArtifact::KIND_DRAFT_SEQUENCE => $this->applySequence($artifact),
            default => 'noop:'.$artifact->kind,
        };

        $now = now();
        $artifact->forceFill([
            'status' => AgentArtifact::STATUS_APPLIED,
            'applied_ref' => mb_substr($ref, 0, 190),
            'applied_at' => $now,
        ])->save();

        if ($artifact->kind === AgentArtifact::KIND_DRAFT_SEQUENCE) {
            $childIds = array_values(array_map('strval', (array) (((array) $artifact->meta)['artifact_ids'] ?? [])));
            if ($childIds !== []) {
                AgentArtifact::forTenant((string) $artifact->tenant_id)->whereIn('id', $childIds)->update([
                    'status' => AgentArtifact::STATUS_APPLIED,
                    'applied_ref' => mb_substr('sequence:'.$artifact->id, 0, 190),
                    'applied_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->events->append((string) $artifact->tenant_id, 'agent_artifact', (string) $artifact->id, 'agent.artifact.applied', [
            'artifact_id' => $artifact->id,
            'kind' => $artifact->kind,
            'run_id' => $artifact->run_id,
            'skill_slug' => $artifact->skill_slug,
            'applied_ref' => $artifact->applied_ref,
        ], ['runtime' => 'php_skill']);
    }

    private function applySequence(AgentArtifact $artifact): string
    {
        $tenantId = (string) $artifact->tenant_id;
        $meta = (array) $artifact->meta;
        $campaign = (string) ($meta['campaign'] ?? $artifact->title ?? 'sequence');
        $campaignKey = Str::slug((string) ($meta['campaign_key'] ?? $campaign), '-') ?: 'sequence';
        $channel = in_array($meta['channel'] ?? 'email', ['email', 'whatsapp', 'linkedin'], true) ? (string) $meta['channel'] : 'email';
        $packId = (string) ($meta['pack_id'] ?? 'sales-crm');
        $steps = $this->stepsWithEdits($artifact, (array) ($meta['steps'] ?? []));

        $keys = [];
        if (Schema::hasTable('message_templates')) {
            foreach ($steps as $step) {
                foreach ($step['variants'] as $variant) {
                    $key = sprintf('outreach.%s.step%d.%s', $campaignKey, $step['n'], $variant['key']);
                    MessageTemplate::updateOrCreate(
                        ['tenant_id' => $tenantId, 'channel' => $channel, 'key' => $key],
                        [
                            'pack_id' => $packId,
                            'subject' => $channel === 'email' && $variant['subject'] !== '' ? mb_substr($variant['subject'], 0, 255) : null,
                            'body' => $variant['body'],
                            'status' => 'active',
                        ],
                    );
                    $keys[] = $key;
                }
            }
        }

        $assetId = null;
        if (Schema::hasTable('business_assets')) {
            $version = (int) DB::table('business_assets')
                ->where('tenant_id', $tenantId)
                ->where('type', 'message_template')
                ->where('name', 'like', "Outreach sequence: {$campaign}%")
                ->count() + 1;

            $asset = BusinessAsset::create([
                'tenant_id' => $tenantId,
                'type' => 'message_template',
                'name' => mb_substr(sprintf('Outreach sequence: %s (%d steps, %s)', $campaign, count($steps), $channel), 0, 255),
                'ref_type' => AgentArtifact::class,
                'ref_id' => $artifact->id,
                'version' => $version,
                'quarter' => now()->format('Y').'-Q'.ceil(now()->month / 3),
                'status' => 'active',
                'created_by' => mb_substr((string) ($artifact->skill_slug ?: 'agent'), 0, 64),
            ]);
            $assetId = $asset->id;
        }

        $this->projectToBrain($tenantId, $artifact, $campaign, $channel, $steps);

        return sprintf('message_templates:%d;business_asset:%s', count($keys), $assetId ?? 'none');
    }

    /**
     * Step content as approved: when the approver edited a step email
     * (PATCH /artifacts/{id}) the edited artifact content wins over the
     * model's original in the sequence meta.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array{n: int, subject: string, body: string, variants: list<array{key: string, subject: string, body: string}>}>
     */
    private function stepsWithEdits(AgentArtifact $sequence, array $steps): array
    {
        $tenantId = (string) $sequence->tenant_id;
        $out = [];

        foreach (array_values($steps) as $i => $step) {
            $n = max(1, (int) ($step['n'] ?? $i + 1));
            $subject = trim((string) ($step['subject'] ?? ''));
            $body = trim((string) ($step['body'] ?? ''));
            $variants = [];
            foreach ((array) ($step['variants'] ?? []) as $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $variants[] = [
                    'key' => (string) ($variant['key'] ?? 'a'),
                    'subject' => trim((string) ($variant['subject'] ?? $subject)),
                    'body' => trim((string) ($variant['body'] ?? $body)),
                ];
            }
            if ($variants === []) {
                $variants[] = ['key' => 'a', 'subject' => $subject, 'body' => $body];
            }

            $child = ! empty($step['artifact_id']) ? AgentArtifact::forTenant($tenantId)->find((string) $step['artifact_id']) : null;
            if ($child !== null) {
                $childMeta = (array) $child->meta;
                $original = (string) ($childMeta['original_content'] ?? '');
                if ($original !== '' && $original !== (string) $child->content) {
                    [$editedSubject, $editedBody] = self::splitDraftEmail((string) $child->content);
                    $variants[0]['subject'] = $editedSubject !== '' ? $editedSubject : $variants[0]['subject'];
                    $variants[0]['body'] = $editedBody !== '' ? $editedBody : $variants[0]['body'];
                    $subject = $variants[0]['subject'];
                    $body = $variants[0]['body'];
                }
            }

            $out[] = ['n' => $n, 'subject' => $subject, 'body' => $body, 'variants' => $variants];
        }

        return $out;
    }

    /** "Subject: X\n\nbody" → [X, body]. */
    public static function splitDraftEmail(string $content): array
    {
        $content = trim($content);
        if (preg_match('/^Subject:\s*(.+?)\r?\n\r?\n(.*)$/s', $content, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return ['', $content];
    }

    /** Projection of the approved sequence into offer/approved-sequences.md (BrainStore, source `agent`). */
    private function projectToBrain(string $tenantId, AgentArtifact $artifact, string $campaign, string $channel, array $steps): void
    {
        $store = Collaborators::brainStore();
        if ($store === null) {
            return;
        }

        $lines = [
            sprintf('_Approved %s · channel: %s · %d steps · artifact `%s`_', now()->toDateString(), $channel, count($steps), $artifact->id),
            '',
        ];
        foreach ($steps as $step) {
            $lines[] = sprintf('### Step %d%s', $step['n'], $step['subject'] !== '' ? ' — '.$step['subject'] : '');
            $lines[] = '';
            $lines[] = $step['body'];
            $lines[] = '';
        }

        try {
            $store->upsertSection(
                $tenantId,
                'offer/approved-sequences.md',
                sprintf('%s (%s)', $campaign, now()->toDateString()),
                implode("\n", $lines),
                'agent',
                $artifact->run_id ? 'run:'.$artifact->run_id : 'artifact:'.$artifact->id,
            );
        } catch (\Throwable $e) {
            Log::warning('approved sequence brain projection failed', ['artifact_id' => $artifact->id, 'error' => $e->getMessage()]);
        }
    }
}
