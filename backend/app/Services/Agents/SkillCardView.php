<?php

declare(strict_types=1);

namespace App\Services\Agents;

use Illuminate\Support\Str;

/**
 * Read-only accessor over a SkillCard (Stream B1's DTO), a `skills.card`
 * jsonb array or a raw card.yaml array. Accepts camelCase DTO properties
 * (displayName, runsOn, postActions, oneStepFurther...) and snake_case keys
 * alike so the runtime does not depend on the DTO's exact shape.
 */
final class SkillCardView
{
    /** @var array<string, mixed> */
    private array $data;

    private function __construct(private readonly object|array $source)
    {
        $this->data = self::normalise($source);
    }

    public static function from(object|array $card): self
    {
        return $card instanceof self ? $card : new self($card);
    }

    /** The original card object (what SkillPromptBuilder / SkillOutputValidator expect). */
    public function source(): object|array
    {
        return $this->source;
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    public function id(): string
    {
        return (string) ($this->data['id'] ?? $this->data['slug'] ?? '');
    }

    public function displayName(): string
    {
        return (string) ($this->data['display_name'] ?? $this->data['name'] ?? Str::headline($this->id()));
    }

    public function version(): string
    {
        return (string) ($this->data['version'] ?? '1.0.0');
    }

    public function pillar(): ?string
    {
        return isset($this->data['pillar']) ? (string) $this->data['pillar'] : null;
    }

    public function coreAgent(): ?string
    {
        return isset($this->data['core_agent']) ? (string) $this->data['core_agent'] : null;
    }

    /** Identity key from identities.yaml (growth, crm, richard...). */
    public function runsOn(): string
    {
        return (string) ($this->data['runs_on'] ?? '');
    }

    public function packId(): ?string
    {
        $pack = $this->data['pack_id'] ?? null;

        return is_string($pack) && $pack !== '' ? $pack : null;
    }

    /** single_shot | agentic */
    public function mode(): string
    {
        $mode = (string) ($this->data['mode'] ?? 'single_shot');

        return $mode === 'agentic' ? 'agentic' : 'single_shot';
    }

    /** @return list<array<string, mixed>> */
    public function brainRequires(): array
    {
        $requires = $this->data['brain']['requires'] ?? [];
        $out = [];
        foreach ((array) $requires as $item) {
            if (is_string($item)) {
                [$path, $section] = array_pad(explode('#', $item, 2), 2, null);
                $out[] = ['path' => $path, 'sections' => $section !== null ? [$section] : []];
            } elseif (is_array($item) && isset($item['path'])) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function brainReads(): array
    {
        return array_values(array_filter(array_map(
            static fn ($item) => is_string($item) ? (string) strtok($item, '#') : (string) ($item['path'] ?? ''),
            (array) ($this->data['brain']['reads'] ?? []),
        )));
    }

    /** @return list<string> distinct paths from brain.requires */
    public function requiredPaths(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $r) => (string) strtok((string) $r['path'], '#'),
            $this->brainRequires(),
        )));
    }

    /** @return list<string> requires ∪ reads, without section anchors */
    public function readPaths(): array
    {
        return array_values(array_unique(array_merge($this->requiredPaths(), $this->brainReads())));
    }

    /** @return list<string> */
    public function tools(): array
    {
        return array_values(array_map('strval', (array) ($this->data['tools'] ?? [])));
    }

    /** @return list<string> */
    public function postActions(): array
    {
        $actions = $this->data['post_actions'] ?? $this->data['run']['post_actions'] ?? [];

        return array_values(array_map('strval', (array) $actions));
    }

    /** @return array<string, mixed> */
    public function approval(): array
    {
        return (array) ($this->data['approval'] ?? []);
    }

    /** @return array<string, mixed> */
    public function autonomy(): array
    {
        return (array) ($this->data['autonomy'] ?? $this->data['pipeline'] ?? []);
    }

    public function defaultAutonomy(): string
    {
        // SkillCard DTO: autonomy['default']; raw card.yaml: pipeline['default_level'].
        $autonomy = $this->autonomy();
        $level = (string) ($autonomy['default'] ?? $autonomy['default_level'] ?? 'human_led');

        return in_array($level, ['human_led', 'assisted', 'autonomous', 'shadow'], true) ? $level : 'human_led';
    }

    /** @return array<string, mixed> */
    public function costBudget(): array
    {
        return (array) ($this->data['cost_budget'] ?? []);
    }

    public function perRunBudgetUsd(): ?float
    {
        $cap = $this->costBudget()['per_run_usd'] ?? null;

        return is_numeric($cap) ? (float) $cap : null;
    }

    public function dailyBudgetUsd(): ?float
    {
        $cap = $this->costBudget()['daily_limit_usd'] ?? null;

        return is_numeric($cap) ? (float) $cap : null;
    }

    /** @return array<string, mixed> */
    public function model(): array
    {
        return (array) ($this->data['model'] ?? []);
    }

    /** @return array<string, mixed> */
    public function limits(): array
    {
        return (array) ($this->data['limits'] ?? []);
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        return (array) ($this->data['run'] ?? []);
    }

    public function runKind(): string
    {
        return (string) ($this->run()['kind'] ?? 'agent');
    }

    public function entryPath(): ?string
    {
        $path = $this->run()['entry_path'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** @return array<string, mixed> */
    public function oneStepFurther(): array
    {
        return (array) ($this->data['one_step_further'] ?? []);
    }

    /** @return array<string, mixed> */
    public function inputs(): array
    {
        return (array) ($this->data['inputs'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function outputs(): array
    {
        return array_values((array) ($this->data['outputs'] ?? []));
    }

    /** @return array<string, mixed> */
    private static function normalise(object|array $card): array
    {
        if (is_array($card)) {
            return self::snakeKeys($card);
        }

        if (method_exists($card, 'toArray')) {
            $array = $card->toArray();
            if (is_array($array)) {
                return self::snakeKeys($array);
            }
        }

        return self::snakeKeys(get_object_vars($card));
    }

    /**
     * Top-level camelCase DTO properties become snake_case card keys; nested
     * values are left as authored (card.yaml is snake_case already).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function snakeKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[is_string($key) ? Str::snake($key) : $key] = $value;
        }

        return $out;
    }
}
