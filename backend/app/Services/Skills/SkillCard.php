<?php

declare(strict_types=1);

namespace App\Services\Skills;

use Symfony\Component\Yaml\Yaml;

/**
 * Read-only view of one skill folder (plan D5):
 * packages/skills/<slug>/{card.yaml, SKILL.md, prompts/task.md, evals/cases.yaml}.
 *
 * `brain.requires` / `brain.reads` are normalised to [{path, sections[]}] with
 * manifest keys (voice.tone) resolved to path#section when a resolver is
 * supplied by SkillRegistry; otherwise they are left as written.
 */
final class SkillCard
{
    /** Phrases no drafting skill may produce (mirrors Outreach\Bot\ReplyPostFilter). */
    public const DEFAULT_BANNED_PHRASES = [
        'guaranteed results', 'guarantee you', 'we guarantee', 'guaranteed', 'earn up to', 'get rich', 'risk-free', 'risk free',
        'exclusive rate', 'special rate', 'custom commission', 'higher commission', 'discount code', 'coupon',
        'limited time only', 'act now', 'once in a lifetime', 'as an ai', 'as an ai language model',
        'i hope this email finds you well', 'hope this finds you well', 'just following up', 'just checking in',
        'circling back', 'bumping this', 'pick your brain',
    ];

    /**
     * @param  array{requires: list<array{path: string, sections: list<string>, key?: string}>, reads: list<array{path: string, sections: list<string>}>, writes: list<string>}  $brain
     * @param  list<string>  $tools
     * @param  array<string, mixed>  $inputs
     * @param  list<array<string, mixed>>  $outputs
     * @param  list<string>  $postActions
     * @param  array<string, mixed>  $approval
     * @param  array{default: string, ladder: list<string>}  $autonomy
     * @param  list<array<string, mixed>>  $triggers
     * @param  array<string, mixed>  $costBudget
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>  $limits
     * @param  array<string, mixed>  $run
     * @param  array<string, mixed>  $oneStepFurther
     * @param  array<string, mixed>  $card
     */
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly string $version,
        public readonly string $pillar,
        public readonly string $coreAgent,
        public readonly string $runsOn,
        public readonly string $mode,
        public readonly ?string $packId,
        public readonly array $brain,
        public readonly array $tools,
        public readonly array $inputs,
        public readonly array $outputs,
        public readonly array $postActions,
        public readonly array $approval,
        public readonly array $autonomy,
        public readonly array $triggers,
        public readonly array $costBudget,
        public readonly array $model,
        public readonly array $limits,
        public readonly array $run,
        public readonly array $oneStepFurther,
        public readonly array $card,
        public readonly string $folder,
        private readonly string $skillMd,
        private readonly string $taskMd,
    ) {}

    /**
     * @param  null|callable(string): ?string  $keyResolver  manifest key → "path#Section"
     */
    public static function fromFolder(string $folder, ?callable $keyResolver = null): self
    {
        $folder = rtrim($folder, '/\\');
        $cardPath = $folder.'/card.yaml';
        if (! is_readable($cardPath)) {
            throw new \RuntimeException("card.yaml not found in {$folder}");
        }
        $card = Yaml::parseFile($cardPath);
        if (! is_array($card)) {
            throw new \RuntimeException("card.yaml in {$folder} is not a mapping");
        }

        return self::fromArray($card, $folder, $keyResolver);
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  null|callable(string): ?string  $keyResolver
     */
    public static function fromArray(array $card, string $folder, ?callable $keyResolver = null): self
    {
        $folder = rtrim($folder, '/\\');
        $skillMd = is_readable($folder.'/SKILL.md') ? (string) file_get_contents($folder.'/SKILL.md') : '';
        $promptRel = (string) (($card['run']['prompt'] ?? null) ?: 'prompts/task.md');
        $taskMd = is_readable($folder.'/'.$promptRel) ? (string) file_get_contents($folder.'/'.$promptRel) : '';

        $pipeline = (array) ($card['pipeline'] ?? []);
        $ladder = array_values(array_map('strval', (array) ($pipeline['ladder'] ?? ['human_led'])));

        return new self(
            id: (string) ($card['id'] ?? basename($folder)),
            displayName: (string) ($card['display_name'] ?? $card['id'] ?? basename($folder)),
            version: (string) ($card['version'] ?? '0.0.0'),
            pillar: (string) ($card['pillar'] ?? ''),
            coreAgent: (string) ($card['core_agent'] ?? ''),
            runsOn: (string) ($card['runs_on'] ?? ''),
            mode: (string) ($card['mode'] ?? 'single_shot'),
            packId: isset($card['pack_id']) && $card['pack_id'] !== null ? (string) $card['pack_id'] : null,
            brain: self::normaliseBrain((array) ($card['brain'] ?? []), $keyResolver),
            tools: array_values(array_map('strval', (array) ($card['tools'] ?? []))),
            inputs: (array) ($card['inputs'] ?? ['type' => 'object']),
            outputs: array_values((array) ($card['outputs'] ?? [])),
            postActions: array_values(array_map('strval', (array) ($card['run']['post_actions'] ?? []))),
            approval: (array) ($card['approval'] ?? []),
            autonomy: ['default' => (string) ($pipeline['default_level'] ?? 'human_led'), 'ladder' => $ladder],
            triggers: array_values((array) ($card['triggers'] ?? [])),
            costBudget: (array) ($card['cost_budget'] ?? []),
            model: (array) ($card['model'] ?? []),
            limits: (array) ($card['limits'] ?? []),
            run: (array) ($card['run'] ?? []),
            oneStepFurther: (array) ($card['one_step_further'] ?? []),
            card: $card,
            folder: $folder,
            skillMd: $skillMd,
            taskMd: $taskMd,
        );
    }

    /**
     * @param  array<string, mixed>  $brain
     * @param  null|callable(string): ?string  $keyResolver
     * @return array{requires: list<array{path: string, sections: list<string>, key?: string}>, reads: list<array{path: string, sections: list<string>}>, writes: list<string>}
     */
    private static function normaliseBrain(array $brain, ?callable $keyResolver): array
    {
        $requires = [];
        foreach ((array) ($brain['requires'] ?? []) as $entry) {
            if (is_string($entry)) {
                $entry = ['path' => $entry];
            }
            $entry = (array) $entry;
            $path = (string) ($entry['path'] ?? '');
            $sections = array_values(array_map('strval', (array) ($entry['sections'] ?? [])));
            [$path, $inlineSection] = self::splitPath($path);
            if ($inlineSection !== null && ! in_array($inlineSection, $sections, true)) {
                $sections[] = $inlineSection;
            }
            $out = ['path' => $path, 'sections' => $sections];
            if (isset($entry['key']) && is_string($entry['key']) && $entry['key'] !== '') {
                $out['key'] = $entry['key'];
                $resolved = $keyResolver ? $keyResolver($entry['key']) : null;
                if (is_string($resolved) && $resolved !== '') {
                    [$resolvedPath, $resolvedSection] = self::splitPath($resolved);
                    if ($out['path'] === '') {
                        $out['path'] = $resolvedPath;
                    }
                    if ($resolvedSection !== null && ! in_array($resolvedSection, $out['sections'], true)) {
                        $out['sections'][] = $resolvedSection;
                    }
                }
            }
            $requires[] = $out;
        }

        $reads = [];
        foreach ((array) ($brain['reads'] ?? []) as $entry) {
            if (is_array($entry)) {
                $path = (string) ($entry['path'] ?? '');
                $sections = array_values(array_map('strval', (array) ($entry['sections'] ?? [])));
                if (isset($entry['key']) && $keyResolver && is_string($resolved = $keyResolver((string) $entry['key'])) && $resolved !== '') {
                    [$rp, $rs] = self::splitPath($resolved);
                    $path = $path !== '' ? $path : $rp;
                    if ($rs !== null) {
                        $sections[] = $rs;
                    }
                }
                [$path, $inline] = self::splitPath($path);
                if ($inline !== null) {
                    $sections[] = $inline;
                }
                $reads[] = ['path' => $path, 'sections' => array_values(array_unique($sections))];

                continue;
            }
            $raw = (string) $entry;
            $resolved = $keyResolver && ! str_contains($raw, '/') ? $keyResolver($raw) : null;
            [$path, $section] = self::splitPath(is_string($resolved) && $resolved !== '' ? $resolved : $raw);
            $reads[] = ['path' => $path, 'sections' => $section !== null ? [$section] : []];
        }

        $writes = array_values(array_map('strval', (array) ($brain['writes'] ?? [])));

        return ['requires' => $requires, 'reads' => $reads, 'writes' => $writes];
    }

    /** @return array{0: string, 1: ?string} path and optional #section */
    public static function splitPath(string $path): array
    {
        $hash = strpos($path, '#');
        if ($hash === false) {
            return [$path, null];
        }

        return [substr($path, 0, $hash), substr($path, $hash + 1) ?: null];
    }

    /** SKILL.md without its frontmatter. */
    public function promptBody(): string
    {
        return trim(self::stripFrontmatter($this->skillMd));
    }

    /** @return array<string, mixed> SKILL.md frontmatter */
    public function skillFrontmatter(): array
    {
        return self::parseFrontmatter($this->skillMd);
    }

    public function skillMarkdown(): string
    {
        return $this->skillMd;
    }

    public function taskTemplate(): string
    {
        return $this->taskMd;
    }

    /**
     * Renders prompts/task.md, replacing {{inputs.x}} / {{inputs.x.y}} with the
     * given inputs (scalars as-is, arrays as JSON, missing as "(not given)").
     * Placeholders that are not under inputs.* (e.g. {{first_line}}) are left
     * verbatim for the model.
     *
     * @param  array<string, mixed>  $inputs
     */
    public function taskPrompt(array $inputs): string
    {
        $defaults = [];
        foreach ((array) ($this->inputs['properties'] ?? []) as $name => $def) {
            if (is_array($def) && array_key_exists('default', $def)) {
                $defaults[$name] = $def['default'];
            } elseif (is_array($def) && array_key_exists('const', $def)) {
                $defaults[$name] = $def['const'];
            }
        }
        $values = array_replace($defaults, $inputs);

        $rendered = preg_replace_callback('/\{\{\s*inputs\.([A-Za-z0-9_.\[\]]+)\s*\}\}/', function (array $m) use ($values): string {
            $value = $this->dig($values, $m[1]);

            return $this->stringify($value);
        }, $this->taskMd);

        return trim((string) $rendered);
    }

    /** @return list<string> */
    public function bannedPhrases(): array
    {
        $extra = array_values(array_map('strval', (array) ($this->card['banned_phrases'] ?? [])));

        return array_values(array_unique(array_merge(self::DEFAULT_BANNED_PHRASES, $extra)));
    }

    /** @return array<string, mixed>|null the JSON schema declared for this output kind */
    public function outputSchema(string $kind): ?array
    {
        foreach ($this->outputs as $output) {
            if (($output['kind'] ?? null) === $kind && is_array($output['schema'] ?? null)) {
                return $output['schema'];
            }
        }

        return null;
    }

    /** The output kind SkillPromptBuilder advertises and SkillOutputValidator checks. */
    public function primaryOutputKind(): ?string
    {
        foreach ($this->outputs as $output) {
            if (! empty($output['primary'])) {
                return (string) $output['kind'];
            }
        }

        return isset($this->outputs[0]['kind']) ? (string) $this->outputs[0]['kind'] : null;
    }

    /** @return list<string> */
    public function intentKeywords(): array
    {
        return array_values(array_map(fn ($k) => mb_strtolower(trim((string) $k)), (array) ($this->card['intent_keywords'] ?? [])));
    }

    /** @return list<string> paths required before a run may start (no sections) */
    public function requiredPaths(): array
    {
        return array_values(array_unique(array_map(fn (array $r) => $r['path'], $this->brain['requires'])));
    }

    /** @return list<array<string, mixed>> */
    public function handsOffTo(): array
    {
        return array_values((array) ($this->card['hands_off_to'] ?? []));
    }

    /** @return list<array<string, mixed>> */
    public function nextStepTemplates(): array
    {
        return array_values((array) ($this->oneStepFurther['steps'] ?? []));
    }

    public function runKind(): string
    {
        return (string) ($this->run['kind'] ?? 'agent');
    }

    /** sha1 over everything that shapes the prompt (SKILL.md body, task template, output schemas). */
    public function templateHash(): string
    {
        return sha1($this->promptBody()."\n--\n".$this->taskMd."\n--\n".json_encode(array_map(fn ($o) => $o['schema'] ?? null, $this->outputs)));
    }

    /** sha256 over the card and prompt as shipped (skills.card_hash). */
    public function contentHash(): string
    {
        return hash('sha256', json_encode($this->card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n".$this->taskMd."\n".$this->skillMd);
    }

    public static function stripFrontmatter(string $markdown): string
    {
        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R?/su', $markdown, $m) === 1) {
            return substr($markdown, strlen($m[0]));
        }

        return $markdown;
    }

    /** @return array<string, mixed> */
    public static function parseFrontmatter(string $markdown): array
    {
        if (preg_match('/\A---\s*\R(.*?)\R---\s*(?:\R|\z)/su', $markdown, $m) !== 1) {
            return [];
        }
        try {
            $parsed = Yaml::parse($m[1]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Extracts one "## Heading" section body from markdown (case-insensitive heading match).
     */
    public static function section(string $markdown, string $heading): ?string
    {
        $body = self::stripFrontmatter($markdown);
        $pattern = '/^##\s+'.preg_quote($heading, '/').'\s*$\R?(.*?)(?=^##\s|\z)/msiu';
        if (preg_match($pattern, $body, $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    private function dig(array $values, string $path): mixed
    {
        $segments = preg_split('/\.|\[|\]\.?/', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $node = $values;
        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '(not given)',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
