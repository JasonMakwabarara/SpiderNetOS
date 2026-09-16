<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

use Symfony\Component\Yaml\Yaml;

/**
 * One golden case from packages/skills/<slug>/evals/cases.yaml (plan D8 #14).
 *
 *   version: 1
 *   skill: cold-email-drafting
 *   fixtures: { base_brain: &base_brain { "offer/offer.md": "…" } }   # YAML anchors are fine
 *   cases:
 *     - id: happy_path_cafes
 *       fixture_brain: *base_brain                 # path => markdown content
 *       inputs: { campaign: cafes-q4, steps: 3 }
 *       expected_raw: '{"steps": [...]}'           # optional stored model output → deterministic replay
 *       expect:
 *         properties:                               # string form `name` / `name:arg` (B1 cards)
 *           - valid_json                            #   or array form {type, path, …}
 *           - steps_count:3
 *           - { type: enum, path: steps.0.beat, values: [problem] }
 *         judge:                                    # pairwise LLM judge (live mode only)
 *           - "Step 1 opens with a specific observation."
 */
final class EvalCase
{
    /**
     * @param  array<string, string>  $fixtureBrain  path => markdown content
     * @param  array<string, mixed>  $inputs
     * @param  list<string|array<string, mixed>>  $properties
     * @param  list<string>  $judges
     * @param  array<string, mixed>  $raw  the case as written (for the report)
     */
    public function __construct(
        public readonly string $id,
        public readonly array $fixtureBrain,
        public readonly array $inputs,
        public readonly array $properties,
        public readonly ?string $expectedRaw,
        public readonly array $judges = [],
        public readonly array $raw = [],
    ) {}

    /** @param array<string, mixed> $case */
    public static function fromArray(array $case, int $index = 0): self
    {
        $expected = $case['expected_raw'] ?? null;
        if (is_array($expected)) {
            $expected = json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $expect = (array) ($case['expect'] ?? []);
        $properties = (array) ($expect['properties'] ?? ($case['properties'] ?? []));
        $judges = (array) ($expect['judge'] ?? ($case['judge'] ?? []));

        $fixture = [];
        foreach ((array) ($case['fixture_brain'] ?? []) as $path => $content) {
            if (is_string($path) && $path !== '<<') {
                $fixture[$path] = is_scalar($content) ? (string) $content : (string) json_encode($content);
            }
        }

        return new self(
            id: (string) ($case['id'] ?? 'case_'.($index + 1)),
            fixtureBrain: $fixture,
            inputs: (array) ($case['inputs'] ?? []),
            properties: array_values(array_filter($properties, fn ($p) => is_string($p) || is_array($p))),
            expectedRaw: is_string($expected) && trim($expected) !== '' ? $expected : null,
            judges: array_values(array_map('strval', array_filter($judges, 'is_scalar'))),
            raw: $case,
        );
    }

    /**
     * @return list<self>
     */
    public static function loadAll(string $file): array
    {
        if (! is_file($file)) {
            throw new \RuntimeException("No eval cases at {$file}.");
        }

        $parsed = Yaml::parseFile($file);
        $cases = is_array($parsed) ? ($parsed['cases'] ?? (isset($parsed[0]) ? $parsed : [])) : [];
        if (! is_array($cases) || $cases === []) {
            throw new \RuntimeException("No `cases:` found in {$file}.");
        }

        $out = [];
        foreach (array_values($cases) as $i => $case) {
            if (is_array($case)) {
                $out[] = self::fromArray($case, $i);
            }
        }

        return $out;
    }

    /** The stored output parsed as JSON when it is JSON, else the raw string. */
    public function expectedOutput(): mixed
    {
        if ($this->expectedRaw === null) {
            return null;
        }
        $decoded = json_decode($this->expectedRaw, true);

        return json_last_error() === JSON_ERROR_NONE && (is_array($decoded)) ? $decoded : $this->expectedRaw;
    }

    /** All fixture markdown as one text (for figure / link / fact lookups). */
    public function fixtureText(): string
    {
        return implode("\n\n", $this->fixtureBrain);
    }

    /** Body of one `## Section` of a fixture file (case-insensitive), or null. */
    public function fixtureSection(string $path, string $section): ?string
    {
        $content = $this->fixtureBrain[$path] ?? null;
        if ($content === null) {
            return null;
        }
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $collect = false;
        $body = [];
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                if ($collect) {
                    break;
                }
                $collect = strcasecmp(trim($m[1]), trim($section)) === 0;

                continue;
            }
            if ($collect) {
                $body[] = $line;
            }
        }

        return $collect ? trim(implode("\n", $body)) : null;
    }

    /** YAML frontmatter of one fixture file (the leading `---` block), or []. */
    public function fixtureFrontmatter(string $path): array
    {
        $content = $this->fixtureBrain[$path] ?? '';
        if (! preg_match('/^---\s*\n(.*?)\n---\s*(?:\n|$)/s', ltrim($content), $m)) {
            return [];
        }
        try {
            $fm = Yaml::parse($m[1]);

            return is_array($fm) ? $fm : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
