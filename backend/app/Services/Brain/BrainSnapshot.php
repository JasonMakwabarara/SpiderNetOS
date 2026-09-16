<?php

declare(strict_types=1);

namespace App\Services\Brain;

use App\Models\BrainFile;
use App\Models\BrainFileVersion;

/**
 * The exact brain a run read: path → version, pinned at dispatch and stored
 * on agent_runs so a run can be replayed against the same context even after
 * the files move on (ADR-0002 D3). capture() reads heads; fromArray()
 * rehydrates the pinned versions from brain_file_versions.
 */
final class BrainSnapshot
{
    /**
     * @param  array<string, array{content: string, version: int, frontmatter: array<string, mixed>}>  $files
     */
    private function __construct(
        private readonly string $tenantId,
        private array $files,
    ) {
        ksort($this->files);
    }

    /**
     * @param  list<string>  $paths  empty = every file the tenant has
     */
    public static function capture(string $tenantId, array $paths = []): self
    {
        $query = BrainFile::forTenant($tenantId);
        if ($paths !== []) {
            $query->whereIn('path', array_map([BrainStore::class, 'normalizePath'], $paths));
        }

        $files = [];
        foreach ($query->orderBy('path')->get() as $file) {
            $files[$file->path] = [
                'content' => (string) $file->content,
                'version' => (int) $file->version,
                'frontmatter' => (array) ($file->frontmatter ?? []),
            ];
        }

        return new self($tenantId, $files);
    }

    /**
     * @param  array<string, int>  $map  path → version (as stored by toArray())
     */
    public static function fromArray(string $tenantId, array $map): self
    {
        $files = [];
        if ($map !== []) {
            $heads = BrainFile::forTenant($tenantId)->whereIn('path', array_keys($map))->get()->keyBy('path');

            foreach ($map as $path => $version) {
                /** @var BrainFile|null $head */
                $head = $heads[$path] ?? null;
                if (! $head) {
                    continue;
                }
                $row = BrainFileVersion::forTenant($tenantId)
                    ->where('brain_file_id', $head->id)
                    ->where('version', (int) $version)
                    ->first();
                if (! $row) {
                    continue;
                }
                $files[(string) $path] = [
                    'content' => (string) $row->content,
                    'version' => (int) $row->version,
                    'frontmatter' => (array) ($row->frontmatter ?? []),
                ];
            }
        }

        return new self($tenantId, $files);
    }

    /** @return array{content: string, version: int, frontmatter: array<string, mixed>}|null */
    public function readAt(string $path): ?array
    {
        return $this->files[BrainStore::normalizePath($path)] ?? null;
    }

    /** @return array<string, int> path → version */
    public function toArray(): array
    {
        $map = [];
        foreach ($this->files as $path => $file) {
            $map[$path] = $file['version'];
        }

        return $map;
    }

    /** Stable digest of the pinned map — equal snapshots hash equal. */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->toArray()));
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_keys($this->files);
    }

    public function count(): int
    {
        return count($this->files);
    }

    public function isEmpty(): bool
    {
        return $this->files === [];
    }
}
