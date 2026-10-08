<?php

declare(strict_types=1);

namespace App\Services\Founder;

use App\Models\BrainFile;
use App\Models\BrainFileVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Small write helper for the founder loop: files the daily brief, upserts a
 * markdown section when an Atlas "one more question" is answered, and
 * drafts proposal content for DistilCorrectionsJob.
 *
 * Goes through App\Services\Brain\BrainStore (Stream A) when that class
 * exists — write(tenant, path, content, frontmatter, source, changedBy,
 * baseVersion, runId, changeNote) / upsertSection(tenant, path, heading,
 * body, source, changedBy) — otherwise writes brain_files +
 * brain_file_versions directly with the same versioning rules (head
 * version + 1, sha256 content hash).
 */
class BrainWriter
{
    public const BRAIN_STORE = 'App\\Services\\Brain\\BrainStore';

    /**
     * Write a whole file. Returns the head version, or null when the brain
     * tables are absent.
     *
     * @param  array<string, mixed>  $options  title, source (human|projection|agent), author_type, author_ref|changed_by, change_summary, data_class, frontmatter
     */
    public function write(string $tenantId, string $path, string $content, array $options = []): ?int
    {
        if (class_exists(self::BRAIN_STORE)) {
            try {
                $store = app(self::BRAIN_STORE);
                if (method_exists($store, 'write')) {
                    $result = $store->write(
                        $tenantId,
                        $path,
                        $content,
                        (array) ($options['frontmatter'] ?? []),
                        $this->sourceOf($options, BrainFile::SOURCE_AGENT),
                        $this->changedByOf($options),
                        null,
                        null,
                        isset($options['change_summary']) ? (string) $options['change_summary'] : null,
                    );
                    $version = $this->versionOf($result);
                    if ($version !== null) {
                        return $version;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('founder.brain_writer.store_failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        return $this->writeDirect($tenantId, $path, $content, $options);
    }

    /**
     * Replace (or append) one `## Section` of a file with a new body.
     */
    public function upsertSection(string $tenantId, string $path, string $section, string $body, array $options = []): ?int
    {
        if (class_exists(self::BRAIN_STORE)) {
            try {
                $store = app(self::BRAIN_STORE);
                if (method_exists($store, 'upsertSection')) {
                    $result = $store->upsertSection($tenantId, $path, $section, $body, $this->sourceOf($options, BrainFile::SOURCE_HUMAN), $this->changedByOf($options));
                    $version = $this->versionOf($result);
                    if ($version !== null) {
                        return $version;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('founder.brain_writer.store_failed', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        $current = $this->read($tenantId, $path) ?? '';

        return $this->writeDirect($tenantId, $path, self::replaceSection($current, $section, $body), $options + [
            'source' => BrainFile::SOURCE_HUMAN,
            'change_summary' => "Updated section \"{$section}\"",
        ]);
    }

    public function read(string $tenantId, string $path): ?string
    {
        if (! Schema::hasTable('brain_files')) {
            return null;
        }
        $file = BrainFile::forTenant($tenantId)->where('path', $path)->first();

        return $file?->content;
    }

    /**
     * Pure markdown helper: replace the body under `## $section` (up to the
     * next `## ` heading) or append the section at the end.
     */
    public static function replaceSection(string $content, string $section, string $body): string
    {
        $body = trim($body);
        $heading = '## '.trim($section);
        $lines = preg_split('/\r?\n/', $content) ?: [];

        $start = null;
        foreach ($lines as $i => $line) {
            if (strcasecmp(trim($line), $heading) === 0) {
                $start = $i;
                break;
            }
        }

        if ($start === null) {
            $trimmed = rtrim($content);

            return ($trimmed === '' ? '' : $trimmed."\n\n").$heading."\n\n".$body."\n";
        }

        $end = count($lines);
        for ($i = $start + 1; $i < count($lines); $i++) {
            if (preg_match('/^##\s/', $lines[$i])) {
                $end = $i;
                break;
            }
        }

        $before = array_slice($lines, 0, $start);
        $after = array_slice($lines, $end);
        $middle = [$heading, '', $body, ''];

        return rtrim(implode("\n", array_merge($before, $middle, $after)))."\n";
    }

    private function sourceOf(array $options, string $default): string
    {
        return in_array($options['source'] ?? null, BrainFile::SOURCES, true) ? (string) $options['source'] : $default;
    }

    private function changedByOf(array $options): ?string
    {
        foreach (['changed_by', 'author_ref'] as $key) {
            if (isset($options[$key]) && is_string($options[$key]) && $options[$key] !== '') {
                return $options[$key];
            }
        }

        return null;
    }

    private function writeDirect(string $tenantId, string $path, string $content, array $options): ?int
    {
        if (! Schema::hasTable('brain_files')) {
            return null;
        }

        return DB::transaction(function () use ($tenantId, $path, $content, $options): int {
            $file = BrainFile::forTenant($tenantId)->where('path', $path)->first();
            $hash = BrainFile::hashContent($content);
            $source = $this->sourceOf($options, BrainFile::SOURCE_AGENT);

            if ($file === null) {
                $file = BrainFile::create([
                    'tenant_id' => $tenantId,
                    'path' => $path,
                    'title' => $options['title'] ?? self::titleFromPath($path),
                    'content' => $content,
                    'frontmatter' => (array) ($options['frontmatter'] ?? []),
                    'source' => $source,
                    'managed' => false,
                    'data_class' => in_array($options['data_class'] ?? null, BrainFile::DATA_CLASSES, true) ? $options['data_class'] : BrainFile::DATA_INTERNAL,
                    'version' => 1,
                    'content_hash' => $hash,
                ]);
            } else {
                if ($file->content_hash === $hash && $file->content === $content) {
                    return (int) $file->version;
                }
                $file->fill([
                    'content' => $content,
                    'title' => $options['title'] ?? $file->title,
                    'version' => (int) $file->version + 1,
                    'content_hash' => $hash,
                ])->save();
            }

            if (Schema::hasTable('brain_file_versions')) {
                BrainFileVersion::create([
                    'tenant_id' => $tenantId,
                    'brain_file_id' => $file->id,
                    'version' => (int) $file->version,
                    'content' => $content,
                    'frontmatter' => (array) $file->frontmatter,
                    'content_hash' => $hash,
                    'source' => $source,
                    'author_type' => (string) ($options['author_type'] ?? BrainFileVersion::AUTHOR_SYSTEM),
                    'author_ref' => $this->changedByOf($options),
                    'change_summary' => isset($options['change_summary']) ? mb_substr((string) $options['change_summary'], 0, 255) : null,
                ]);
            }

            return (int) $file->version;
        });
    }

    private function versionOf(mixed $result): ?int
    {
        if (is_int($result)) {
            return $result;
        }
        if (is_array($result) && isset($result['version'])) {
            return (int) $result['version'];
        }
        if (is_object($result) && isset($result->version)) {
            return (int) $result->version;
        }
        if ($result === true || $result === null) {
            return 0;
        }

        return null;
    }

    public static function titleFromPath(string $path): string
    {
        $base = (string) preg_replace('/\.(md|yaml|yml|txt)$/', '', basename($path));

        return ucfirst(str_replace(['-', '_'], ' ', $base));
    }
}
