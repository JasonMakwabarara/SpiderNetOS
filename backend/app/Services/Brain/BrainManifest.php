<?php

declare(strict_types=1);

namespace App\Services\Brain;

use Symfony\Component\Yaml\Yaml;

/**
 * Reader for packages/brain/manifest.yaml — the canonical layout of a
 * tenant's Knowledge brain (ADR-0002 D2). Answers "which paths exist, what
 * sections must they carry, what do we ask when one is missing, how
 * sensitive is the file, when does it go stale" for BrainStore,
 * BrainGapAnalyzer, BrainSyncService, SkillRegistry and the cockpit.
 *
 * Paths come in two shapes: exact (`business/profile.md`) and patterns
 * (`processes/<slug>.md`, `notes/**`). fileSpec() resolves a concrete path
 * to the most specific matching declaration.
 */
final class BrainManifest
{
    public const DEFAULT_DATA_CLASS = 'internal';

    /** @var array<string, mixed> */
    private array $raw;

    /** @var array<string, array<string, mixed>> exact path → spec */
    private array $exact = [];

    /** @var list<array{pattern: string, regex: string, specificity: int, spec: array<string, mixed>}> */
    private array $patterns = [];

    /** @var array<string, string> key → `path#section` */
    private array $keys = [];

    /** @var array<string, array<string, mixed>> */
    private array $folders = [];

    /** @var array<string, list<array{path: string, section: string}>> interview question id → targets */
    private array $interview = [];

    public function __construct(?string $path = null)
    {
        $path ??= (string) config('agents.brain_manifest');
        if ($path === '' || ! is_readable($path)) {
            throw new \RuntimeException("Brain manifest not readable: {$path}");
        }

        $parsed = Yaml::parseFile($path);
        if (! is_array($parsed)) {
            throw new \RuntimeException("Brain manifest is not a YAML mapping: {$path}");
        }

        $this->raw = $parsed;
        $this->index();
    }

    public function version(): int
    {
        return (int) ($this->raw['version'] ?? 1);
    }

    /**
     * Every declared path (exact and pattern), keyed by the declared path.
     *
     * @return array<string, array<string, mixed>>
     */
    public function paths(): array
    {
        $all = $this->exact;
        foreach ($this->patterns as $entry) {
            $all[$entry['pattern']] = $entry['spec'];
        }

        return $all;
    }

    /**
     * Exact (non-pattern) paths only — the files a tenant is expected to have.
     *
     * @return list<string>
     */
    public function canonicalPaths(): array
    {
        return array_keys($this->exact);
    }

    /**
     * Folder declarations ordered by `order`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function folders(): array
    {
        return $this->folders;
    }

    /** @return array<string, string> */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * `voice.tone` → ['path' => 'brand/voice.md', 'section' => 'Tone'].
     * A literal `path#section` string is accepted too so callers can pass
     * either form.
     *
     * @return array{path: string, section: ?string}
     */
    public function resolveKey(string $key): array
    {
        $key = trim($key);
        $target = $this->keys[$key] ?? null;

        if ($target === null) {
            if (! str_contains($key, '/')) {
                throw new \InvalidArgumentException("Unknown brain key: {$key}");
            }
            $target = $key;
        }

        [$path, $section] = array_pad(explode('#', $target, 2), 2, null);
        $section = $section !== null ? trim($section) : null;

        return ['path' => trim((string) $path), 'section' => $section === '' ? null : $section];
    }

    /**
     * Declaration for a concrete path: exact match first, then the most
     * specific matching pattern (`notes/research/**` beats `notes/**`).
     *
     * @return array<string, mixed>|null
     */
    public function fileSpec(string $path): ?array
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if (isset($this->exact[$path])) {
            return $this->exact[$path];
        }

        foreach ($this->patterns as $entry) {
            if (preg_match($entry['regex'], $path)) {
                return $entry['spec'];
            }
        }

        return null;
    }

    public function isKnown(string $path): bool
    {
        return $this->fileSpec($path) !== null;
    }

    /**
     * Section declarations for a path, keyed by section name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sections(string $path): array
    {
        $spec = $this->fileSpec($path);
        $out = [];
        foreach ((array) ($spec['sections'] ?? []) as $section) {
            if (is_array($section) && isset($section['name'])) {
                $out[(string) $section['name']] = $section;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function sectionSpec(string $path, string $section): ?array
    {
        foreach ($this->sections($path) as $name => $spec) {
            if (strcasecmp($name, $section) === 0) {
                return $spec;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function requiredSections(string $path): array
    {
        $names = [];
        foreach ($this->sections($path) as $name => $spec) {
            if (! empty($spec['required'])) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The question Atlas asks when a section is missing. With a null section,
     * the first required section's question (falling back to any section
     * with a question).
     */
    public function question(string $path, ?string $section): ?string
    {
        if ($section !== null) {
            $spec = $this->sectionSpec($path, $section);
            $question = $spec['question'] ?? null;

            return is_string($question) && $question !== '' ? $question : null;
        }

        $fallback = null;
        foreach ($this->sections($path) as $spec) {
            $question = $spec['question'] ?? null;
            if (! is_string($question) || $question === '') {
                continue;
            }
            if (! empty($spec['required'])) {
                return $question;
            }
            $fallback ??= $question;
        }

        return $fallback;
    }

    public function dataClass(string $path): string
    {
        $class = $this->fileSpec($path)['data_class'] ?? null;

        return is_string($class) && $class !== '' ? $class : self::DEFAULT_DATA_CLASS;
    }

    public function staleAfterDays(string $path): ?int
    {
        $days = $this->fileSpec($path)['stale_after_days'] ?? null;

        return $days === null ? null : (int) $days;
    }

    public function title(string $path): string
    {
        $spec = $this->fileSpec($path);
        if ($spec !== null && empty($spec['pattern']) && ! empty($spec['title'])) {
            return (string) $spec['title'];
        }

        return self::humanize($path);
    }

    public function source(string $path): string
    {
        return (string) ($this->fileSpec($path)['source'] ?? 'human');
    }

    public function isManaged(string $path): bool
    {
        return (bool) ($this->fileSpec($path)['managed'] ?? false);
    }

    public function isAgentPrivate(string $path): bool
    {
        return (bool) ($this->fileSpec($path)['agent_private'] ?? false);
    }

    /** @return list<string> */
    public function frontmatterKeys(string $path): array
    {
        return array_values(array_map('strval', (array) ($this->fileSpec($path)['frontmatter'] ?? [])));
    }

    /** @return list<string> */
    public function readinessOrder(): array
    {
        return array_values(array_map('strval', (array) ($this->raw['readiness_order'] ?? [])));
    }

    /**
     * Brain sections a discovery-interview answer fills.
     *
     * @return list<array{path: string, section: string}>
     */
    public function interviewTargets(string $questionId): array
    {
        return $this->interview[$questionId] ?? [];
    }

    /** @return array<string, list<array{path: string, section: string}>> */
    public function interviewMap(): array
    {
        return $this->interview;
    }

    public static function humanize(string $path): string
    {
        $base = basename($path);
        $base = (string) preg_replace('/\.(md|ya?ml|json|txt)$/i', '', $base);

        return ucfirst(str_replace(['-', '_'], ' ', $base));
    }

    /** Glob-ish pattern (`<slug>`, `*`, `**`) → anchored regex. */
    public static function patternToRegex(string $pattern): string
    {
        $parts = preg_split('/(<[^>]+>|\*\*|\*)/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            $regex .= match (true) {
                $part === '**' => '.+',
                $part === '*' => '[^/]*',
                str_starts_with($part, '<') => '[^/]+',
                default => preg_quote($part, '#'),
            };
        }

        return '#^'.$regex.'$#';
    }

    private function index(): void
    {
        foreach ((array) ($this->raw['paths'] ?? []) as $spec) {
            if (! is_array($spec) || empty($spec['path'])) {
                continue;
            }
            $path = trim((string) $spec['path'], '/');
            $spec['path'] = $path;
            $isPattern = ! empty($spec['pattern']) || preg_match('/[*<]/', $path) === 1;
            $spec['pattern'] = $isPattern;

            if ($isPattern) {
                $literal = (string) preg_replace('/(<[^>]+>|\*+)/', '', $path);
                $this->patterns[] = [
                    'pattern' => $path,
                    'regex' => self::patternToRegex($path),
                    'specificity' => strlen($literal),
                    'spec' => $spec,
                ];
            } else {
                $this->exact[$path] = $spec;
            }

            foreach ((array) ($spec['sections'] ?? []) as $section) {
                $questionId = $section['interview_question_id'] ?? null;
                if (is_array($section) && is_string($questionId) && $questionId !== '' && ! $isPattern) {
                    $this->interview[$questionId][] = ['path' => $path, 'section' => (string) $section['name']];
                }
            }
        }

        usort($this->patterns, static fn (array $a, array $b) => $b['specificity'] <=> $a['specificity']);

        foreach ((array) ($this->raw['keys'] ?? []) as $key => $target) {
            if (is_string($target) && $target !== '') {
                $this->keys[(string) $key] = trim($target);
            }
        }

        $folders = [];
        foreach ((array) ($this->raw['folders'] ?? []) as $slug => $meta) {
            $folders[(string) $slug] = is_array($meta) ? $meta : ['title' => (string) $meta];
        }
        uasort($folders, static fn (array $a, array $b) => ((int) ($a['order'] ?? 999)) <=> ((int) ($b['order'] ?? 999)));
        $this->folders = $folders;
    }
}
