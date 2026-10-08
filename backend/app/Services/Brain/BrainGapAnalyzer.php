<?php

declare(strict_types=1);

namespace App\Services\Brain;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What the brain still does not know (plan D2). Given what a skill requires
 * — manifest keys (`voice.tone`) or `{path, sections?}` entries — it lists
 * every missing file, missing section, too-short section and stale file
 * with the manifest's question, so Atlas can ask exactly one thing and a
 * run can block with 422 missing_brain. readiness() is the cockpit's
 * ✓ / ◐ / ○ view over the manifest's readiness_order.
 *
 * analyze() and readinessFor() are pure (files handed in) so they unit test
 * without a database; gaps() and readiness() read through BrainStore.
 */
final class BrainGapAnalyzer
{
    public const REASON_MISSING_FILE = 'missing_file';

    public const REASON_MISSING_SECTION = 'missing_section';

    public const REASON_TOO_SHORT = 'too_short';

    public const REASON_STALE = 'stale';

    public const STATUS_FILLED = 'filled';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_MISSING = 'missing';

    public function __construct(
        private readonly BrainStore $store,
        private readonly BrainManifest $manifest,
    ) {}

    /**
     * @param  list<string|array{path?: string, key?: string, sections?: list<string>}>  $requires
     * @return list<array{path: string, section: ?string, question: ?string, reason: string}>
     */
    public function gaps(string $tenantId, array $requires): array
    {
        $targets = $this->normalizeRequires($requires);

        return $this->analyze($this->load($tenantId, array_keys($targets)), $targets);
    }

    /**
     * @return array{pct: int, files: list<array<string, mixed>>}
     */
    public function readiness(string $tenantId): array
    {
        $order = $this->manifest->readinessOrder();

        return $this->readinessFor($this->load($tenantId, $order), $order);
    }

    /**
     * Pure gap analysis.
     *
     * @param  array<string, array{content: string, updated_at?: mixed}|null>  $files  path → file or null when absent
     * @param  array<string, list<string>>  $targets  path → sections ([] = the manifest's required sections)
     * @return list<array{path: string, section: ?string, question: ?string, reason: string}>
     */
    public function analyze(array $files, array $targets, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $gaps = [];
        $seen = [];

        $add = function (string $path, ?string $section, string $reason) use (&$gaps, &$seen): void {
            $key = $path.'#'.($section ?? '').'#'.$reason;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $gaps[] = [
                'path' => $path,
                'section' => $section,
                'question' => $this->manifest->question($path, $section),
                'reason' => $reason,
            ];
        };

        foreach ($targets as $path => $sections) {
            $wanted = $sections === [] ? $this->manifest->requiredSections($path) : $sections;
            $file = $files[$path] ?? null;

            if ($file === null) {
                if ($wanted === []) {
                    $add($path, null, self::REASON_MISSING_FILE);
                }
                foreach ($wanted as $section) {
                    if ($this->isInferred($path, $section)) {
                        continue;
                    }
                    $add($path, $section, self::REASON_MISSING_FILE);
                }

                continue;
            }

            $present = BrainMarkdown::sections((string) ($file['content'] ?? ''));
            foreach ($wanted as $section) {
                if ($this->isInferred($path, $section)) {
                    continue;
                }
                $state = $this->sectionState($present, $path, $section);
                if ($state !== 'ok') {
                    $add($path, $section, $state);
                }
            }

            $days = $this->manifest->staleAfterDays($path);
            $updatedAt = self::toCarbon($file['updated_at'] ?? null);
            if ($days !== null && $updatedAt !== null && $updatedAt->lt($now->copy()->subDays($days))) {
                $add($path, null, self::REASON_STALE);
            }
        }

        return $gaps;
    }

    /**
     * Pure readiness view.
     *
     * @param  array<string, array{content: string, version?: int, updated_at?: mixed}|null>  $files
     * @param  list<string>  $order
     * @return array{pct: int, files: list<array<string, mixed>>}
     */
    public function readinessFor(array $files, array $order): array
    {
        $totalRequired = 0;
        $filledRequired = 0;
        $out = [];

        foreach ($order as $path) {
            $required = $this->manifest->requiredSections($path);
            $file = $files[$path] ?? null;
            $present = $file !== null ? BrainMarkdown::sections((string) ($file['content'] ?? '')) : [];
            $filled = [];
            $askPrompt = null;
            $askSection = null;

            foreach ($required as $section) {
                if ($this->isInferred($path, $section)) {
                    continue;
                }
                $totalRequired++;
                if ($file !== null && $this->sectionState($present, $path, $section) === 'ok') {
                    $filled[] = $section;
                    $filledRequired++;
                } elseif ($askPrompt === null) {
                    $askPrompt = $this->manifest->question($path, $section);
                    $askSection = $section;
                }
            }

            $out[] = [
                'path' => $path,
                'title' => $this->manifest->title($path),
                'status' => self::statusFrom($file !== null, count($required), count($filled), $present),
                'required' => $required,
                'filled' => $filled,
                'ask_prompt' => $askPrompt,
                'ask_section' => $askSection,
                'version' => $file !== null ? (int) ($file['version'] ?? 0) : 0,
                'data_class' => $this->manifest->dataClass($path),
            ];
        }

        return [
            'pct' => $totalRequired > 0 ? (int) round(100 * $filledRequired / $totalRequired) : 100,
            'files' => $out,
        ];
    }

    /**
     * filled | partial | missing for one file's content against its manifest
     * declaration (used by BrainStore::tree()). Unknown paths and paths with
     * no required sections are "filled" as soon as they carry any prose.
     *
     * @param  array<string, mixed>|null  $spec
     */
    public static function statusFor(?string $content, ?array $spec): string
    {
        if ($content === null) {
            return self::STATUS_MISSING;
        }
        $present = BrainMarkdown::sections($content);
        $required = [];
        foreach ((array) ($spec['sections'] ?? []) as $section) {
            if (is_array($section) && ! empty($section['required']) && empty($section['inferred'])) {
                $required[] = $section;
            }
        }

        if ($required === []) {
            return BrainMarkdown::proseLength(BrainMarkdown::body($content)) > 0 ? self::STATUS_FILLED : self::STATUS_MISSING;
        }

        $filled = 0;
        foreach ($required as $section) {
            if (self::sectionStateFor($present, (string) $section['name'], $section) === 'ok') {
                $filled++;
            }
        }

        return self::statusFrom(true, count($required), $filled, $present);
    }

    /**
     * ok | missing_section | too_short for one section of one file.
     *
     * @param  array<string, string>  $present  parsed sections
     * @param  array<string, mixed>|null  $sectionSpec
     */
    public static function sectionStateFor(array $present, string $section, ?array $sectionSpec): string
    {
        $body = null;
        foreach ($present as $name => $text) {
            if (strcasecmp((string) $name, $section) === 0) {
                $body = $text;
                break;
            }
        }
        if ($body === null) {
            return self::REASON_MISSING_SECTION;
        }

        $length = BrainMarkdown::proseLength($body);
        if ($length === 0) {
            return self::REASON_MISSING_SECTION;
        }
        $min = max(1, (int) ($sectionSpec['min_chars'] ?? 1));

        return $length < $min ? self::REASON_TOO_SHORT : 'ok';
    }

    /**
     * Skill-card `brain.requires` entries, manifest keys or literal paths →
     * path → sections.
     *
     * @param  list<string|array{path?: string, key?: string, sections?: list<string>}>  $requires
     * @return array<string, list<string>>
     */
    public function normalizeRequires(array $requires): array
    {
        $targets = [];

        foreach ($requires as $item) {
            $path = null;
            $sections = [];

            if (is_string($item)) {
                $resolved = $this->manifest->resolveKey($item);
                $path = $resolved['path'];
                $sections = $resolved['section'] !== null ? [$resolved['section']] : [];
            } elseif (is_array($item)) {
                if (! empty($item['key']) && is_string($item['key'])) {
                    $resolved = $this->manifest->resolveKey($item['key']);
                    $path = $resolved['path'];
                    $sections = $resolved['section'] !== null ? [$resolved['section']] : [];
                }
                if (! empty($item['path']) && is_string($item['path'])) {
                    $resolved = $this->manifest->resolveKey($item['path']);
                    $path = $resolved['path'];
                    if ($resolved['section'] !== null) {
                        $sections[] = $resolved['section'];
                    }
                }
                foreach ((array) ($item['sections'] ?? []) as $section) {
                    if (is_string($section) && trim($section) !== '') {
                        $sections[] = trim($section);
                    }
                }
            }

            if ($path === null || $path === '') {
                continue;
            }
            $path = BrainStore::normalizePath($path);
            $existing = $targets[$path] ?? null;

            // A bare path (all required sections) subsumes any narrower entry.
            if ($existing === [] || $sections === []) {
                $targets[$path] = [];

                continue;
            }
            $targets[$path] = array_values(array_unique(array_merge($existing ?? [], $sections)));
        }

        return $targets;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, array{content: string, version: int, updated_at: mixed}|null>
     */
    private function load(string $tenantId, array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $file = $this->store->read($tenantId, $path);
            $files[$path] = $file ? [
                'content' => (string) $file->content,
                'version' => (int) $file->version,
                'updated_at' => $file->updated_at,
            ] : null;
        }

        return $files;
    }

    /** @param array<string, string> $present */
    private function sectionState(array $present, string $path, string $section): string
    {
        return self::sectionStateFor($present, $section, $this->manifest->sectionSpec($path, $section));
    }

    private function isInferred(string $path, string $section): bool
    {
        return (bool) ($this->manifest->sectionSpec($path, $section)['inferred'] ?? false);
    }

    /** @param array<string, string> $present */
    private static function statusFrom(bool $exists, int $required, int $filled, array $present): string
    {
        if (! $exists) {
            return self::STATUS_MISSING;
        }
        if ($required === 0) {
            foreach ($present as $body) {
                if (BrainMarkdown::proseLength($body) > 0) {
                    return self::STATUS_FILLED;
                }
            }

            return self::STATUS_MISSING;
        }
        if ($filled >= $required) {
            return self::STATUS_FILLED;
        }

        return $filled > 0 ? self::STATUS_PARTIAL : self::STATUS_MISSING;
    }

    private static function toCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
