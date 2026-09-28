<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentArtifact;
use App\Models\BusinessAsset;
use App\Models\MessageTemplate;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *
 * It applies only the version that was approved. The payload is built from
 * the database (ApplicationPayload), hashed, and compared with the approval's
 * `approved_version_hash` before anything is written; a difference means the
 * content changed after the decision, and nothing is applied. What is
 * applied is that same payload — the approval view, the hash and the
 * application no longer read three different sources.
 */
final class ArtifactApplier
{
    public function __construct(private readonly EventStore $events) {}

    /**
     * Apply the bundle whose primary artifact is $primary, provided it is
     * still exactly the version that was approved.
     *
     * @throws BundleIntegrityException the content no longer matches, or the bundle does not hold together
     */
    public function applyApproved(AgentArtifact $primary, string $approvedHash): void
    {
        if ($primary->isApplied()) {
            return;
        }

        $payload = ApplicationPayload::for($primary);
        if (! hash_equals($approvedHash, ApplicationPayload::hash($payload))) {
            throw new BundleIntegrityException("The content of {$primary->id} changed after it was approved; nothing was applied.");
        }

        $now = now();
        if ($payload['kind'] === AgentArtifact::KIND_DRAFT_SEQUENCE) {
            $ref = $this->applySequence($primary, $payload);
            $members = array_column($payload['steps'], 'artifact_id');
            AgentArtifact::forTenant((string) $primary->tenant_id)->whereIn('id', $members)->update([
                'status' => AgentArtifact::STATUS_APPLIED,
                'applied_ref' => mb_substr('sequence:'.$primary->id, 0, 190),
                'applied_at' => $now,
                'updated_at' => $now,
            ]);
            $this->markApplied($primary, $ref, $now);

            return;
        }

        foreach ($payload['items'] as $item) {
            $artifact = AgentArtifact::forTenant((string) $primary->tenant_id)->findOrFail($item['id']);
            $this->markApplied($artifact, 'noop:'.$artifact->kind, $now);
        }
    }

    private function markApplied(AgentArtifact $artifact, string $ref, \DateTimeInterface $now): void
    {
        $artifact->forceFill([
            'status' => AgentArtifact::STATUS_APPLIED,
            'applied_ref' => mb_substr($ref, 0, 190),
            'applied_at' => $now,
        ])->save();

        $this->events->append((string) $artifact->tenant_id, 'agent_artifact', (string) $artifact->id, 'agent.artifact.applied', [
            'artifact_id' => $artifact->id,
            'kind' => $artifact->kind,
            'run_id' => $artifact->run_id,
            'skill_slug' => $artifact->skill_slug,
            'applied_ref' => $artifact->applied_ref,
        ], ['runtime' => 'php_skill']);
    }

    /** @param array<string, mixed> $payload */
    private function applySequence(AgentArtifact $artifact, array $payload): string
    {
        $tenantId = (string) $artifact->tenant_id;
        ['campaign' => $campaign, 'campaign_key' => $campaignKey, 'channel' => $channel, 'pack_id' => $packId] = $payload['destination'];
        $steps = $payload['steps'];

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

        // The projection may fail without undoing the application, but never
        // silently: the applied reference says it is missing.
        $projected = $this->projectToBrain($tenantId, $artifact, $campaign, $channel, $steps);

        return sprintf('message_templates:%d;business_asset:%s', count($keys), $assetId ?? 'none')
            .($projected === false ? ';brain_projection:failed' : '');
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

    /**
     * Projection of the approved sequence into offer/approved-sequences.md
     * (BrainStore, source `agent`). Null when no brain store is installed,
     * otherwise whether the projection was written.
     */
    private function projectToBrain(string $tenantId, AgentArtifact $artifact, string $campaign, string $channel, array $steps): ?bool
    {
        $store = Collaborators::brainStore();
        if ($store === null) {
            return null;
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

        // In a savepoint: the approval hook applies inside its own transaction,
        // and a swallowed failure there would otherwise abort it on Postgres.
        return BestEffort::succeeded(
            fn () => $store->upsertSection(
                $tenantId,
                'offer/approved-sequences.md',
                sprintf('%s (%s)', $campaign, now()->toDateString()),
                implode("\n", $lines),
                'agent',
                $artifact->run_id ? 'run:'.$artifact->run_id : 'artifact:'.$artifact->id,
            ),
            'approved sequence brain projection failed',
            ['artifact_id' => $artifact->id],
        );
    }
}
