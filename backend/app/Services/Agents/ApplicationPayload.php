<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentArtifact;
use App\Services\Agents\Exceptions\BundleIntegrityException;
use App\Support\CanonicalJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What approving a bundle would actually do — built once, and used for all
 * three things that must agree: showing the approval, hashing the version,
 * and applying it.
 *
 * Before this, the three read different sources. The approval preview was
 * the sequence's markdown `content`. The applier used `meta.steps`, overlaid
 * with a child's edit — and only on the first variant, so variant `b` kept
 * the model's original body after the approver had rewritten it. And
 * nothing hashed anything, so what was approved and what was applied could
 * differ without trace.
 *
 * Everything is read from the database at the moment of building (see
 * CanonicalJson on why). For a sequence, every child is verified to belong
 * to the bundle: same tenant and run, a draft email, pointing back at this
 * sequence, and listed among its members. A step can therefore no longer be
 * re-pointed at another run's artifact, which `stepsWithEdits()` used to
 * fetch by tenant and id alone.
 */
final class ApplicationPayload
{
    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     *
     * @throws BundleIntegrityException
     */
    public static function for(AgentArtifact $primary): array
    {
        $fresh = AgentArtifact::forTenant((string) $primary->tenant_id)->find($primary->id);
        if ($fresh === null) {
            throw new BundleIntegrityException("Artifact {$primary->id} no longer exists.");
        }

        return $fresh->kind === AgentArtifact::KIND_DRAFT_SEQUENCE ? self::sequence($fresh) : self::items($fresh);
    }

    /** @param array<string, mixed> $payload */
    public static function hash(array $payload): string
    {
        return CanonicalJson::hash($payload);
    }

    /** @return array<string, mixed> */
    private static function sequence(AgentArtifact $sequence): array
    {
        $meta = (array) $sequence->meta;
        $members = array_values(array_map('strval', (array) ($meta['artifact_ids'] ?? [])));
        $children = AgentArtifact::forTenant((string) $sequence->tenant_id)->whereIn('id', $members)->get()->keyBy('id');

        $steps = [];
        $used = [];
        foreach (array_values((array) ($meta['steps'] ?? [])) as $i => $step) {
            $step = (array) $step;
            $n = max(1, (int) ($step['n'] ?? $i + 1));
            $child = self::ownedChild($sequence, $children, $members, (string) ($step['artifact_id'] ?? ''), $n, $used);
            $used[] = (string) $child->id;

            $originalSubject = trim((string) ($step['subject'] ?? ''));
            $originalBody = trim((string) ($step['body'] ?? ''));

            // The child is the reviewable unit: its content is the step as it
            // stands. An edit that drops the subject line keeps the subject.
            [$editedSubject, $editedBody] = ArtifactApplier::splitDraftEmail((string) $child->content);
            $subject = $editedSubject !== '' ? $editedSubject : $originalSubject;
            $body = $editedBody !== '' ? $editedBody : $originalBody;

            $steps[] = [
                'n' => $n,
                'artifact_id' => (string) $child->id,
                'subject' => $subject,
                'body' => $body,
                'delay_days' => isset($step['delay_days']) ? (int) $step['delay_days'] : null,
                'variants' => self::variants($step, $originalSubject, $originalBody, $subject, $body),
            ];
        }

        $unused = array_diff($members, $used);
        if ($unused !== []) {
            throw new BundleIntegrityException('Bundle members that no step uses: '.implode(', ', $unused).'.');
        }

        $campaign = (string) ($meta['campaign'] ?? $sequence->title ?? 'sequence');
        $channel = (string) ($meta['channel'] ?? 'email');

        return [
            'v' => self::VERSION,
            'tenant_id' => (string) $sequence->tenant_id,
            'run_id' => (string) $sequence->run_id,
            'artifact_id' => (string) $sequence->id,
            'kind' => AgentArtifact::KIND_DRAFT_SEQUENCE,
            'destination' => [
                'campaign' => $campaign,
                'campaign_key' => Str::slug((string) ($meta['campaign_key'] ?? $campaign), '-') ?: 'sequence',
                'channel' => in_array($channel, ['email', 'whatsapp', 'linkedin'], true) ? $channel : 'email',
                'pack_id' => (string) ($meta['pack_id'] ?? 'sales-crm'),
            ],
            'steps' => $steps,
        ];
    }

    /**
     * A standalone draft and the siblings its approval covers, as they stand.
     *
     * @return array<string, mixed>
     */
    private static function items(AgentArtifact $artifact): array
    {
        $bundle = collect([$artifact]);
        if ($artifact->approval_id !== null) {
            $bundle = $bundle->merge(AgentArtifact::forTenant((string) $artifact->tenant_id)->where('approval_id', $artifact->approval_id)->get());
        }

        $items = [];
        foreach ($bundle->unique('id')->sortBy('id')->values() as $item) {
            if ((string) $item->run_id !== (string) $artifact->run_id) {
                throw new BundleIntegrityException("Artifact {$item->id} shares approval {$artifact->approval_id} but belongs to another run.");
            }
            $items[] = ['id' => (string) $item->id, 'kind' => (string) $item->kind, 'title' => (string) $item->title, 'content' => (string) $item->content];
        }

        return [
            'v' => self::VERSION,
            'tenant_id' => (string) $artifact->tenant_id,
            'run_id' => (string) $artifact->run_id,
            'artifact_id' => (string) $artifact->id,
            'kind' => (string) $artifact->kind,
            'items' => $items,
        ];
    }

    /**
     * @param  Collection<string, AgentArtifact>  $children
     * @param  list<string>  $members
     * @param  list<string>  $used
     */
    private static function ownedChild(AgentArtifact $sequence, Collection $children, array $members, string $childId, int $n, array $used): AgentArtifact
    {
        $child = $children->get($childId);
        $fault = match (true) {
            ! in_array($childId, $members, true) => 'names an artifact outside the bundle',
            $child === null => 'names a bundle member that does not exist',
            in_array($childId, $used, true) => 'names an artifact another step already uses',
            (string) $child->run_id !== (string) $sequence->run_id => 'names an artifact from another run',
            $child->kind !== AgentArtifact::KIND_DRAFT_EMAIL => "names a {$child->kind}, not a draft email",
            (string) (((array) $child->meta)['sequence_id'] ?? '') !== (string) $sequence->id => 'names an artifact that belongs to another sequence',
            default => null,
        };
        if ($fault !== null) {
            throw new BundleIntegrityException("Step {$n} of sequence {$sequence->id} {$fault} ({$childId}).");
        }

        return $child;
    }

    /**
     * The step's variants with an edit carried into every one it applies to.
     * A variant whose subject or body was a copy of the step's original text
     * takes the edited text; one with its own authored text keeps it — the
     * edit did not touch it, so applying the original is still applying what
     * was reviewed.
     *
     * @param  array<string, mixed>  $step
     * @return list<array{key: string, subject: string, body: string}>
     */
    private static function variants(array $step, string $originalSubject, string $originalBody, string $subject, string $body): array
    {
        $out = [];
        foreach ((array) ($step['variants'] ?? []) as $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $variantSubject = trim((string) ($variant['subject'] ?? $originalSubject));
            $variantBody = trim((string) ($variant['body'] ?? $originalBody));
            $out[] = [
                'key' => (string) ($variant['key'] ?? 'a'),
                'subject' => $variantSubject === $originalSubject ? $subject : $variantSubject,
                'body' => $variantBody === $originalBody ? $body : $variantBody,
            ];
        }

        return $out !== [] ? $out : [['key' => 'a', 'subject' => $subject, 'body' => $body]];
    }
}
