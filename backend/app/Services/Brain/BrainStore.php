<?php

declare(strict_types=1);

namespace App\Services\Brain;

use App\Models\BrainFile;
use App\Models\BrainFileVersion;
use App\Services\EventStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read/write gateway for the Knowledge brain (ADR-0002 D2). Every write goes
 * through write(): a transaction with lockForUpdate on the head row, a
 * version bump, an append-only brain_file_versions row, a content hash, the
 * manifest's data_class, and a `brain.file.updated` event. Optimistic
 * concurrency: pass the `base_version` you read and a stale write throws
 * BrainConflictException (the API turns it into 409).
 *
 * Storage rule: `content` is the markdown body only. A `---` frontmatter
 * block handed to write() is lifted into the `frontmatter` column so the
 * structured facts live in one place; withFrontmatter() recombines them for
 * export and for the editor.
 */
final class BrainStore
{
    public const CHANGED_BY_SYNC = 'brain:sync';

    public function __construct(
        private readonly BrainManifest $manifest,
        private readonly EventStore $events,
    ) {}

    /**
     * The head of a path, or a read-only model hydrated from one historical
     * version when $version is given (never persisted — `exists` is false).
     */
    public function read(string $tenantId, string $path, ?int $version = null): ?BrainFile
    {
        $path = self::normalizePath($path);
        $file = BrainFile::forTenant($tenantId)->where('path', $path)->first();
        if (! $file) {
            return null;
        }
        if ($version === null || $version === (int) $file->version) {
            return $file;
        }

        $row = BrainFileVersion::forTenant($tenantId)
            ->where('brain_file_id', $file->id)
            ->where('version', $version)
            ->first();
        if (! $row) {
            return null;
        }

        $raw = $row->getAttributes();
        $pinned = $file->newInstance([], false);
        $pinned->setRawAttributes(array_merge($file->getAttributes(), [
            'content' => (string) ($raw['content'] ?? ''),
            'frontmatter' => $raw['frontmatter'] ?? '{}',
            'version' => (int) $row->version,
            'content_hash' => (string) $row->content_hash,
            'source' => (string) $row->source,
            'updated_at' => $raw['created_at'] ?? null,
        ]), true);
        $pinned->exists = false;

        return $pinned;
    }

    /**
     * Folder → files view for the cockpit: every manifest folder in order,
     * with the files present (status/version/updated_at/data_class) and the
     * canonical files still missing (so readiness can be shown per folder).
     *
     * @return list<array{folder: string, title: string, order: int, agent_private: bool, files: list<array<string, mixed>>}>
     */
    public function tree(string $tenantId): array
    {
        $byFolder = [];
        $present = [];

        foreach (BrainFile::forTenant($tenantId)->orderBy('path')->get() as $file) {
            $present[$file->path] = true;
            $byFolder[self::topFolder($file->path)][] = $this->summarize($file);
        }

        foreach ($this->manifest->canonicalPaths() as $path) {
            if (isset($present[$path])) {
                continue;
            }
            $byFolder[self::topFolder($path)][] = [
                'path' => $path,
                'title' => $this->manifest->title($path),
                'status' => 'missing',
                'exists' => false,
                'version' => 0,
                'updated_at' => null,
                'data_class' => $this->manifest->dataClass($path),
                'source' => $this->manifest->source($path),
                'managed' => false,
                'size' => 0,
            ];
        }

        $out = [];
        foreach ($this->manifest->folders() as $slug => $meta) {
            $files = $byFolder[$slug] ?? [];
            usort($files, static fn (array $a, array $b) => strcmp($a['path'], $b['path']));
            $out[] = [
                'folder' => $slug,
                'title' => (string) ($meta['title'] ?? ucfirst($slug)),
                'order' => (int) ($meta['order'] ?? 999),
                'agent_private' => (bool) ($meta['agent_private'] ?? false),
                'files' => $files,
            ];
            unset($byFolder[$slug]);
        }
        foreach ($byFolder as $slug => $files) {
            usort($files, static fn (array $a, array $b) => strcmp($a['path'], $b['path']));
            $out[] = ['folder' => $slug, 'title' => ucfirst($slug), 'order' => 999, 'agent_private' => false, 'files' => $files];
        }
        usort($out, static fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $out;
    }

    /**
     * Write a new head version. No-op (same model returned, no version bump,
     * no event) when content and frontmatter are unchanged.
     *
     * @param  array<string, mixed>  $frontmatter  explicit frontmatter; merged over any `---` block in $content
     * @param  string  $source  human | projection | agent
     * @param  ?int  $baseVersion  the version the caller read; 0/null for "new file" semantics only when null
     *
     * @throws BrainConflictException when $baseVersion is given and stale
     */
    public function write(
        string $tenantId,
        string $path,
        string $content,
        array $frontmatter = [],
        string $source = BrainFile::SOURCE_HUMAN,
        ?string $changedBy = null,
        ?int $baseVersion = null,
        ?string $runId = null,
        ?string $changeNote = null,
    ): BrainFile {
        $path = self::normalizePath($path);
        self::assertValidPath($path);
        if (! in_array($source, BrainFile::SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown brain source: {$source}");
        }

        [$inline, $body] = BrainMarkdown::splitFrontmatter(BrainMarkdown::normalizeNewlines($content));
        $frontmatter = array_replace($inline, $frontmatter);
        $body = rtrim($body, "\n");
        $body = $body === '' ? '' : $body."\n";

        /** @var array{0: BrainFile, 1: bool} $result */
        $result = DB::transaction(function () use ($tenantId, $path, $body, $frontmatter, $source, $changedBy, $baseVersion, $runId, $changeNote) {
            $file = BrainFile::forTenant($tenantId)->where('path', $path)->lockForUpdate()->first();
            $current = $file ? (int) $file->version : 0;

            if ($baseVersion !== null && $baseVersion !== $current) {
                throw new BrainConflictException($path, $current, $baseVersion);
            }

            $hash = BrainFile::hashContent($body);
            if ($file && $file->content_hash === $hash && self::sameFrontmatter((array) ($file->frontmatter ?? []), $frontmatter)) {
                return [$file, false];
            }

            $version = $current + 1;
            $attributes = [
                'title' => $this->titleFor($path, $body, $frontmatter),
                'content' => $body,
                'frontmatter' => $frontmatter,
                'source' => $source,
                'managed' => BrainMarkdown::isWhollyManaged($body),
                'data_class' => $this->manifest->dataClass($path),
                'version' => $version,
                'content_hash' => $hash,
            ];

            if ($file) {
                $file->fill($attributes)->save();
            } else {
                $file = BrainFile::create(['tenant_id' => $tenantId, 'path' => $path] + $attributes);
            }

            BrainFileVersion::create([
                'tenant_id' => $tenantId,
                'brain_file_id' => $file->id,
                'version' => $version,
                'content' => $body,
                'frontmatter' => $frontmatter,
                'content_hash' => $hash,
                'source' => $source,
                'author_type' => self::authorType($source),
                'author_ref' => $changedBy ?? $runId ?? ($source === BrainFile::SOURCE_PROJECTION ? self::CHANGED_BY_SYNC : null),
                'change_summary' => $changeNote !== null ? mb_substr($changeNote, 0, 255) : null,
                'created_at' => now(),
            ]);

            return [$file, true];
        });

        [$file, $changed] = $result;
        if ($changed) {
            $this->emit($tenantId, 'brain.file.updated', $file, [
                'path' => $path,
                'version' => (int) $file->version,
                'source' => $source,
                'changed_by' => $changedBy,
                'run_id' => $runId,
            ]);
        }

        return $file;
    }

    /**
     * Replace (or append) one `## Heading` section, preserving frontmatter and
     * every other section.
     */
    public function upsertSection(
        string $tenantId,
        string $path,
        string $heading,
        string $body,
        string $source = BrainFile::SOURCE_HUMAN,
        ?string $changedBy = null,
    ): BrainFile {
        $file = $this->read($tenantId, $path);
        $content = BrainMarkdown::upsertSection($file?->content ?? '', $heading, $body);

        return $this->write(
            $tenantId,
            $path,
            $content,
            (array) ($file?->frontmatter ?? []),
            $source,
            $changedBy,
            null,
            null,
            'Updated section "'.$heading.'"',
        );
    }

    /** @return array<string, string> heading → body */
    public function sections(string $content): array
    {
        return BrainMarkdown::sections($content);
    }

    /** @return Collection<int, BrainFileVersion> newest first */
    public function versions(string $tenantId, string $path): Collection
    {
        $file = $this->read($tenantId, $path);
        if (! $file) {
            return new Collection;
        }

        return BrainFileVersion::forTenant($tenantId)
            ->where('brain_file_id', $file->id)
            ->orderByDesc('version')
            ->get();
    }

    /** Restore an earlier version as a new head version (history is never rewritten). */
    public function revert(string $tenantId, string $path, int $version, ?string $changedBy = null): BrainFile
    {
        $path = self::normalizePath($path);
        $file = $this->read($tenantId, $path);
        if (! $file) {
            throw (new ModelNotFoundException)->setModel(BrainFile::class, [$path]);
        }

        $row = BrainFileVersion::forTenant($tenantId)
            ->where('brain_file_id', $file->id)
            ->where('version', $version)
            ->first();
        if (! $row) {
            throw (new ModelNotFoundException)->setModel(BrainFileVersion::class, [$path.'@'.$version]);
        }

        return $this->write(
            $tenantId,
            $path,
            (string) $row->content,
            (array) ($row->frontmatter ?? []),
            BrainFile::SOURCE_HUMAN,
            $changedBy,
            null,
            null,
            "Reverted to v{$version}",
        );
    }

    public function delete(string $tenantId, string $path): void
    {
        $path = self::normalizePath($path);
        $file = BrainFile::forTenant($tenantId)->where('path', $path)->first();
        if (! $file) {
            return;
        }

        $version = (int) $file->version;
        DB::transaction(function () use ($tenantId, $file) {
            BrainFileVersion::forTenant($tenantId)->where('brain_file_id', $file->id)->delete();
            $file->delete();
        });

        $this->emit($tenantId, 'brain.file.deleted', $file, ['path' => $path, 'version' => $version, 'source' => BrainFile::SOURCE_HUMAN]);
    }

    /** @return array<string, mixed> */
    public function frontmatter(string $content): array
    {
        return BrainMarkdown::frontmatter($content);
    }

    /** @param array<string, mixed> $fm */
    public function withFrontmatter(array $fm, string $body): string
    {
        return BrainMarkdown::withFrontmatter($fm, $body);
    }

    /** Full text of a file as a human would see it on disk: frontmatter block + body. */
    public function render(BrainFile $file): string
    {
        return BrainMarkdown::withFrontmatter((array) ($file->frontmatter ?? []), (string) $file->content);
    }

    /** @return array<string, mixed> */
    public function summarize(BrainFile $file): array
    {
        return [
            'path' => $file->path,
            'title' => $file->title ?? $this->manifest->title($file->path),
            'status' => BrainGapAnalyzer::statusFor((string) $file->content, $this->manifest->fileSpec($file->path)),
            'exists' => true,
            'version' => (int) $file->version,
            'updated_at' => $file->updated_at?->toIso8601String(),
            'data_class' => $file->data_class,
            'source' => $file->source,
            'managed' => (bool) $file->managed,
            'size' => strlen((string) $file->content),
        ];
    }

    public static function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', trim($path)), '/');
    }

    public static function isValidPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 255) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', $segment)) {
                return false;
            }
        }

        return true;
    }

    public static function assertValidPath(string $path): void
    {
        if (! self::isValidPath($path)) {
            throw new \InvalidArgumentException("Invalid brain path: {$path}");
        }
    }

    public static function topFolder(string $path): string
    {
        $pos = strpos($path, '/');

        return $pos === false ? '' : substr($path, 0, $pos);
    }

    public static function authorType(string $source): string
    {
        return match ($source) {
            BrainFile::SOURCE_AGENT => BrainFileVersion::AUTHOR_AGENT,
            BrainFile::SOURCE_PROJECTION => BrainFileVersion::AUTHOR_SYSTEM,
            default => BrainFileVersion::AUTHOR_USER,
        };
    }

    /** @param array<string, mixed> $frontmatter */
    private function titleFor(string $path, string $body, array $frontmatter): string
    {
        $title = $frontmatter['title'] ?? null;
        if (is_string($title) && trim($title) !== '') {
            return mb_substr(trim($title), 0, 255);
        }
        $h1 = BrainMarkdown::title($body);
        if ($h1 !== null && $h1 !== '') {
            return mb_substr($h1, 0, 255);
        }

        return $this->manifest->title($path);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function sameFrontmatter(array $a, array $b): bool
    {
        return json_encode(self::sortKeys($a)) === json_encode(self::sortKeys($b));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortKeys(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::sortKeys($v);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function emit(string $tenantId, string $type, BrainFile $file, array $payload): void
    {
        try {
            $this->events->append($tenantId, 'brain_file', (string) $file->id, $type, $payload);
        } catch (\Throwable $e) {
            Log::warning('brain.event_failed', ['tenant_id' => $tenantId, 'path' => $file->path, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
