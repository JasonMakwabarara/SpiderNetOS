<?php

declare(strict_types=1);

namespace App\Services\ZetKai;

use App\Models\BrainFile;
use App\Services\FeatureFlag;
use App\Services\Founder\BrainWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pulls ZetKai's vault into the Knowledge brain under `notes/zetkai/**`
 * (plan D7 §4).
 *
 * What crosses:   the prose of a note, its title, its categories, its ZetKai
 *                 id and its updated_at.
 * What does not:  vectors (different embedding spaces — a copied one would be
 *                 a meaningless number that poisons retrieval silently rather
 *                 than failing), and anything the privacy guard withholds.
 *
 * The cursor lives in `tenant_integrations.config` and is only advanced after
 * a page is written. A crash mid-page repeats that page next time, which is
 * harmless — every write is keyed on the ZetKai id — whereas advancing the
 * cursor first would drop notes with nothing to say it had happened.
 *
 * A note deleted in ZetKai is tombstoned rather than removed: the brain file
 * keeps its path with a `deleted: true` frontmatter, so anything that linked
 * to it still resolves to an honest "this was deleted" instead of a 404.
 */
class ZetKaiSyncService
{
    public const BRAIN_PREFIX = 'notes/zetkai/';

    /** Stop a runaway vault from filling the brain in one pass. */
    public const MAX_NOTES_PER_RUN = 500;

    public function __construct(
        private readonly ZetKaiClient $client,
        private readonly ZetKaiPrivacyGuard $privacy,
        private readonly BrainWriter $writer,
    ) {}

    public function enabled(string $tenantId): bool
    {
        return FeatureFlag::on('zetkai.enabled', $tenantId) && $this->client->configured($tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $tenantId): array
    {
        $integration = $this->client->integrationFor($tenantId);
        $config = (array) ($integration->config ?? []);

        return [
            'enabled' => FeatureFlag::on('zetkai.enabled', $tenantId),
            'connected' => $this->client->configured($tenantId),
            'cursor' => $config['cursor'] ?? null,
            'last_synced_at' => $config['last_synced_at'] ?? null,
            'notes_filed' => (int) ($config['notes_filed'] ?? 0),
            'private_withheld' => (int) ($config['private_withheld'] ?? 0),
            'nightly' => FeatureFlag::on('zetkai.nightly_sync', $tenantId),
            'brain_prefix' => self::BRAIN_PREFIX,
        ];
    }

    /**
     * Pull one page of changes.
     *
     * @return array{synced: bool, reason: string, filed: int, tombstoned: int, withheld: int, cursor: string|null}
     */
    public function sync(string $tenantId): array
    {
        if (! FeatureFlag::on('zetkai.enabled', $tenantId)) {
            return $this->nothing('disabled');
        }
        if (! $this->client->configured($tenantId)) {
            return $this->nothing('not_connected');
        }

        $integration = $this->client->integrationFor($tenantId);
        $config = (array) ($integration?->config ?? []);
        $since = isset($config['cursor']) ? (string) $config['cursor'] : null;

        $changes = $this->client->changes($tenantId, $since);

        // The guard runs before anything is written, and its count is reported
        // rather than swallowed: a filter that silently starts excluding
        // everything looks identical to an empty vault.
        $verdict = $this->privacy->filter($changes['notes'], $tenantId);

        $filed = 0;
        $tombstoned = 0;

        foreach (array_slice($verdict['allowed'], 0, self::MAX_NOTES_PER_RUN) as $note) {
            $this->fileOne($tenantId, $note) ? $filed++ : $tombstoned++;
        }

        if ($integration !== null) {
            $integration->forceFill([
                // array_merge, not +: with union the existing cursor would win
                // and the sync would replay the same page forever.
                'config' => array_merge($config, [
                    'cursor' => $changes['cursor'] ?? $since,
                    'last_synced_at' => now()->toIso8601String(),
                    'notes_filed' => (int) ($config['notes_filed'] ?? 0) + $filed,
                    'private_withheld' => (int) ($config['private_withheld'] ?? 0) + $verdict['excluded'],
                ]),
            ])->save();
        }

        Log::info('zetkai.synced', [
            'tenant_id' => $tenantId, 'filed' => $filed, 'tombstoned' => $tombstoned, 'withheld' => $verdict['excluded'],
        ]);

        return [
            'synced' => true,
            'reason' => 'pulled',
            'filed' => $filed,
            'tombstoned' => $tombstoned,
            'withheld' => $verdict['excluded'],
            'cursor' => $changes['cursor'] ?? $since,
        ];
    }

    /** True when the note was filed, false when it was tombstoned. */
    private function fileOne(string $tenantId, array $note): bool
    {
        $path = self::pathFor($note);
        $deleted = ! empty($note['deleted_at']) || ! empty($note['deleted']);

        $frontmatter = array_filter([
            'zetkai_id' => (string) ($note['id'] ?? ''),
            'title' => $this->title($note),
            'categories' => array_values(array_filter(array_map(
                fn ($c): string => is_array($c) ? (string) ($c['name'] ?? $c['slug'] ?? '') : (string) $c,
                (array) ($note['categories'] ?? []),
            ))),
            'entity_type' => is_array($note['entity_type'] ?? null) ? ($note['entity_type']['name'] ?? null) : ($note['entity_type'] ?? null),
            'source' => 'zetkai',
            'zetkai_updated_at' => $note['updated_at'] ?? null,
            'deleted' => $deleted ?: null,
        ], fn ($v): bool => $v !== null && $v !== '' && $v !== []);

        $body = $deleted
            // Tombstone, not deletion: anything that linked here still resolves
            // to an honest answer rather than a missing file.
            ? 'This note was deleted in ZetKai on '.($note['deleted_at'] ?? now()->toDateString()).".\n"
            : trim((string) ($note['content'] ?? $note['body'] ?? ''));

        $this->writer->write($tenantId, $path, $body, [
            'title' => $this->title($note),
            'source' => 'projection',
            'author_type' => 'system',
            'author_ref' => 'ZetKaiSyncService',
            'change_summary' => $deleted ? 'Deleted in ZetKai' : 'Synced from ZetKai',
            // No data_class here on purpose: the brain manifest already
            // classifies notes/zetkai/** as `personal`, which is stricter than
            // anything this service would have asked for and is the right
            // place for that decision to live.
            'frontmatter' => $frontmatter,
        ]);

        return ! $deleted;
    }

    /** `notes/zetkai/<id>-<slug>.md` — the id first, so a retitled note keeps its file. */
    public static function pathFor(array $note): string
    {
        $id = (string) ($note['id'] ?? Str::uuid());
        $slug = Str::slug((string) ($note['title'] ?? 'note')) ?: 'note';

        return self::BRAIN_PREFIX.mb_substr($id, 0, 40).'-'.mb_substr($slug, 0, 60).'.md';
    }

    private function title(array $note): string
    {
        $title = trim((string) ($note['title'] ?? ''));

        return $title !== '' ? mb_substr($title, 0, 190) : 'Untitled ZetKai note';
    }

    /** @return array{synced: bool, reason: string, filed: int, tombstoned: int, withheld: int, cursor: null} */
    private function nothing(string $reason): array
    {
        return ['synced' => false, 'reason' => $reason, 'filed' => 0, 'tombstoned' => 0, 'withheld' => 0, 'cursor' => null];
    }

    /** Every ZetKai-sourced file currently in this tenant's brain. */
    public function filed(string $tenantId): int
    {
        return BrainFile::forTenant($tenantId)->where('path', 'like', self::BRAIN_PREFIX.'%')->count();
    }
}
