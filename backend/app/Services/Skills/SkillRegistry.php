<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Services\Skills\Eval\PropertyChecker;
use App\Services\Skills\Eval\PropertyRegistry;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads every packages/skills/<slug>/ folder into SkillCard DTOs (cached per
 * process), resolves brain keys through packages/brain/manifest.yaml, maps
 * identities through packages/skills/identities.yaml and validates cards
 * against the schema plus the cross-file rules `skills:validate` enforces.
 */
class SkillRegistry
{
    /** @var array<string, array<string, SkillCard>> root => slug => card */
    private static array $cache = [];

    /** @var array<string, array<string, list<string>>> root => slug => load errors */
    private static array $loadErrors = [];

    private readonly string $root;

    private ?array $identities = null;

    private ?array $manifest = null;

    private ?SkillSchemaValidator $schema = null;

    private ?array $principleIds = null;

    public function __construct(?string $root = null)
    {
        $root ??= (string) config('agents.skills_root');
        $this->root = rtrim($root, '/\\');
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$loadErrors = [];
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return array<string, SkillCard> slug => card, sorted by slug */
    public function all(): array
    {
        if (isset(self::$cache[$this->root])) {
            return self::$cache[$this->root];
        }

        $cards = [];
        $errors = [];
        foreach ($this->folders() as $folder) {
            $slug = basename($folder);
            try {
                $cards[$slug] = SkillCard::fromFolder($folder, fn (string $key) => $this->resolveBrainKey($key));
            } catch (\Throwable $e) {
                $errors[$slug] = ["card.yaml: {$e->getMessage()}"];
            }
        }
        ksort($cards);
        self::$cache[$this->root] = $cards;
        self::$loadErrors[$this->root] = $errors;

        return $cards;
    }

    public function get(string $slug): ?SkillCard
    {
        return $this->all()[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return $this->get($slug) !== null;
    }

    /** @return array<string, SkillCard> cards with an event trigger for this event_type */
    public function forTrigger(string $eventType): array
    {
        $out = [];
        foreach ($this->all() as $slug => $card) {
            foreach ($card->triggers as $trigger) {
                if (($trigger['type'] ?? null) === 'event' && ($trigger['event'] ?? null) === $eventType) {
                    $out[$slug] = $card;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Keyword match over card.intent_keywords[] and display names: the card
     * whose matched phrases cover the most characters of the message wins.
     */
    public function matchIntent(string $message): ?SkillCard
    {
        $haystack = mb_strtolower(' '.preg_replace('/\s+/u', ' ', trim($message)).' ');
        if (trim($haystack) === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;
        foreach ($this->all() as $card) {
            $phrases = array_merge(
                $card->intentKeywords(),
                [mb_strtolower($card->displayName), str_replace('-', ' ', $card->id)],
            );
            $score = 0;
            foreach (array_unique($phrases) as $phrase) {
                $phrase = trim($phrase);
                if ($phrase === '') {
                    continue;
                }
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $haystack) === 1) {
                    $score += mb_strlen($phrase);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $card;
            }
        }

        return $best;
    }

    /** @return array<string, list<string>> slug => errors (empty list when valid) */
    public function validateAll(): array
    {
        $out = [];
        foreach ($this->all() as $slug => $card) {
            $out[$slug] = $this->validate($card);
        }
        foreach (self::$loadErrors[$this->root] ?? [] as $slug => $errors) {
            $out[$slug] = $errors;
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> */
    public function validate(SkillCard $card): array
    {
        $errors = $this->schemaValidator()->validate($card->card);

        $slug = basename($card->folder);
        if ($card->id !== $slug) {
            $errors[] = "id: \"{$card->id}\" must equal the folder name \"{$slug}\"";
        }

        $identities = $this->identities();
        $identity = $identities['identities'][$card->runsOn] ?? null;
        if ($identity === null) {
            $errors[] = "runs_on: unknown identity \"{$card->runsOn}\" (see identities.yaml)";
        } elseif (! in_array($card->id, (array) ($identity['skills'] ?? []), true)) {
            $errors[] = "runs_on: identity \"{$card->runsOn}\" does not list \"{$card->id}\" under skills in identities.yaml";
        }
        if (! in_array($card->coreAgent, $this->coreCharacters(), true)) {
            $errors[] = "core_agent: unknown character \"{$card->coreAgent}\"";
        }

        // Brain references resolve against packages/brain/manifest.yaml.
        foreach ((array) ($card->card['brain']['requires'] ?? []) as $i => $raw) {
            $raw = (array) $raw;
            if (isset($raw['key'])) {
                $resolved = $this->resolveBrainKey((string) $raw['key']);
                if ($resolved === null) {
                    $errors[] = "brain.requires[{$i}].key: \"{$raw['key']}\" is not a key in the brain manifest";
                } elseif (! empty($raw['path'])) {
                    [$resolvedPath] = SkillCard::splitPath($resolved);
                    [$givenPath] = SkillCard::splitPath((string) $raw['path']);
                    if ($resolvedPath !== $givenPath) {
                        $errors[] = "brain.requires[{$i}]: key \"{$raw['key']}\" resolves to {$resolvedPath}, not {$givenPath}";
                    }
                }
            }
        }
        foreach ($card->brain['requires'] as $i => $entry) {
            $errors = array_merge($errors, $this->validateBrainPath($entry['path'], $entry['sections'], "brain.requires[{$i}]"));
        }
        foreach ($card->brain['reads'] as $i => $entry) {
            $errors = array_merge($errors, $this->validateBrainPath($entry['path'], $entry['sections'], "brain.reads[{$i}]"));
        }
        foreach ($card->brain['writes'] as $i => $path) {
            [$p, $s] = SkillCard::splitPath($path);
            $errors = array_merge($errors, $this->validateBrainPath($p, $s ? [$s] : [], "brain.writes[{$i}]"));
        }

        // Tools must exist in the catalogue (config/agents.php tool_costs).
        foreach ($card->tools as $i => $tool) {
            if (! $this->isKnownTool($tool)) {
                $errors[] = "tools[{$i}]: unknown tool \"{$tool}\"";
            }
        }

        // Cross-card references: known card, planned roster skill, identity, character, product.
        foreach ((array) ($card->card['breaks_into'] ?? []) as $i => $item) {
            if (! empty($item['slug']) && ! $this->isKnownOrPlanned((string) $item['slug'])) {
                $errors[] = "breaks_into[{$i}].slug: unknown skill \"{$item['slug']}\"";
            }
        }
        foreach ((array) ($card->card['builds_on'] ?? []) as $i => $item) {
            if (! empty($item['slug']) && ! $this->isKnownOrPlanned((string) $item['slug'])) {
                $errors[] = "builds_on[{$i}].slug: unknown skill \"{$item['slug']}\"";
            }
            if (! empty($item['ref'])) {
                $errors = array_merge($errors, $this->validateRef((string) $item['ref'], "builds_on[{$i}].ref"));
            }
        }
        foreach ($card->handsOffTo() as $i => $item) {
            $type = (string) ($item['type'] ?? '');
            $ref = (string) ($item['ref'] ?? '');
            if ($type === 'skill') {
                if (empty($item['slug'])) {
                    $errors[] = "hands_off_to[{$i}]: type skill requires slug";
                } elseif (! $this->isKnownOrPlanned((string) $item['slug'])) {
                    $errors[] = "hands_off_to[{$i}].slug: unknown skill \"{$item['slug']}\"";
                }
            } elseif ($type === 'identity' && ! isset($identities['identities'][$ref])) {
                $errors[] = "hands_off_to[{$i}].ref: unknown identity \"{$ref}\"";
            } elseif ($type === 'character' && ! in_array($ref, $this->coreCharacters(), true)) {
                $errors[] = "hands_off_to[{$i}].ref: unknown character \"{$ref}\"";
            } elseif ($type === 'product' && ! isset($identities['external_refs'][$ref])) {
                $errors[] = "hands_off_to[{$i}].ref: unknown product \"{$ref}\" (identities.yaml external_refs)";
            }
        }

        // One step further: every step names a known/planned skill and valid paths.
        $inputNames = array_keys((array) ($card->inputs['properties'] ?? []));
        $outputKeys = $this->outputKeys($card);
        foreach ($card->nextStepTemplates() as $i => $step) {
            $skill = (string) ($step['skill'] ?? '');
            if (! $this->isKnownOrPlanned($skill)) {
                $errors[] = "one_step_further.steps[{$i}].skill: unknown skill \"{$skill}\"";
            }
            foreach ((array) ($step['inputs_from'] ?? []) as $target => $source) {
                $source = (string) $source;
                $segments = preg_split('/\.|\[/', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $rootKey = $segments[0] ?? '';
                $first = $segments[1] ?? '';
                if ($rootKey === 'inputs' && ! in_array($first, $inputNames, true)) {
                    $errors[] = "one_step_further.steps[{$i}].inputs_from.{$target}: \"{$source}\" names no input of this card";
                } elseif ($rootKey === 'outputs' && ! in_array($first, $outputKeys, true)) {
                    $errors[] = "one_step_further.steps[{$i}].inputs_from.{$target}: \"{$source}\" names no output key of this card";
                }
                $targetCard = $this->get($skill);
                if ($targetCard && ! array_key_exists($target, (array) ($targetCard->inputs['properties'] ?? []))) {
                    $errors[] = "one_step_further.steps[{$i}].inputs_from.{$target}: \"{$skill}\" has no input \"{$target}\"";
                }
            }
            foreach ((array) ($step['requires_brain'] ?? []) as $j => $path) {
                [$p, $s] = SkillCard::splitPath((string) $path);
                $errors = array_merge($errors, $this->validateBrainPath($p, $s ? [$s] : [], "one_step_further.steps[{$i}].requires_brain[{$j}]"));
            }
        }

        if (! in_array($card->autonomy['default'], $card->autonomy['ladder'], true)) {
            $errors[] = 'pipeline.default_level: must be one of the ladder levels';
        }

        // SKILL.md: frontmatter and the five body sections.
        if ($card->skillMarkdown() === '') {
            $errors[] = 'SKILL.md: missing';
        } else {
            $fm = $card->skillFrontmatter();
            foreach (['name', 'display_name', 'description', 'category', 'pillar', 'runs_on', 'core_agent', 'version'] as $key) {
                if (! isset($fm[$key]) || $fm[$key] === '') {
                    $errors[] = "SKILL.md: frontmatter missing \"{$key}\"";
                }
            }
            if (($fm['name'] ?? null) !== $card->id) {
                $errors[] = 'SKILL.md: frontmatter name must equal the card id';
            }
            if (isset($fm['version']) && (string) $fm['version'] !== $card->version) {
                $errors[] = 'SKILL.md: frontmatter version must equal card.yaml version';
            }
            foreach (['Role', 'Frame', 'Reads before it writes', 'Output contract', 'Guardrails'] as $heading) {
                if (SkillCard::section($card->skillMarkdown(), $heading) === null) {
                    $errors[] = "SKILL.md: missing \"## {$heading}\" section";
                }
            }
        }

        // prompts/task.md: present and every {{inputs.*}} placeholder is a declared input.
        if ($card->taskTemplate() === '') {
            $errors[] = 'prompts/task.md: missing';
        } elseif (preg_match_all('/\{\{\s*inputs\.([A-Za-z0-9_]+)/', $card->taskTemplate(), $m) > 0) {
            foreach (array_unique($m[1]) as $name) {
                if (! in_array($name, $inputNames, true)) {
                    $errors[] = "prompts/task.md: placeholder {{inputs.{$name}}} names no input of this card";
                }
            }
        }

        // Outputs: the primary output must carry a JSON schema.
        $primary = $card->primaryOutputKind();
        if ($primary === null) {
            $errors[] = 'outputs: at least one output is required';
        } elseif ($card->outputSchema($primary) === null) {
            $errors[] = "outputs: primary output \"{$primary}\" has no schema";
        }

        // Run kind files.
        if ($card->runKind() === 'interview') {
            $rel = (string) ($card->run['interview'] ?? '');
            if ($rel === '' || ! is_readable($card->folder.'/'.$rel)) {
                $errors[] = "run.interview: \"{$rel}\" not found in the card folder";
            }
        }

        // Evals: the cases file exists, parses and has at least one case.
        $cases = (string) ($card->card['evals']['cases'] ?? '');
        if ($cases !== '') {
            $casesPath = $card->folder.'/'.$cases;
            if (! is_readable($casesPath)) {
                $errors[] = "evals.cases: \"{$cases}\" not found";
            } else {
                try {
                    $parsed = Yaml::parseFile($casesPath);
                    $list = is_array($parsed) ? (array) ($parsed['cases'] ?? []) : [];
                    if ($list === []) {
                        $errors[] = 'evals.cases: no cases defined';
                    }
                    foreach ($list as $i => $case) {
                        foreach (['id', 'inputs', 'expect'] as $key) {
                            if (! is_array($case) || ! array_key_exists($key, $case)) {
                                $errors[] = "evals.cases[{$i}]: missing \"{$key}\"";
                            }
                        }

                        $where = 'evals.cases['.(is_array($case) ? (string) ($case['id'] ?? $i) : (string) $i).']';
                        foreach ($this->propertyErrors($case, $where) as $error) {
                            $errors[] = $error;
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = "evals.cases: {$e->getMessage()}";
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Every property a case names must be a type the registry declares, and its
     * argument must fit that type's shape.
     *
     * This is the gate that makes `unknown property type` unreachable from a
     * committed corpus. Before it, a case could name fifty-one checks nothing
     * implements and `skills:validate` would pass it — the structure was
     * checked (id, inputs, expect) and the contents were not.
     *
     * A `planned` type is deliberately NOT an error yet. It is declared, owned
     * and dated, and it still cannot pass an eval; naming one becomes an error
     * once the corpus has stopped using them. What is an error today is a name
     * nothing declares at all, which can only be a typo or an invention, and an
     * argument that cannot work — `slots_count:two` should never reach a model.
     *
     * @param  mixed  $case  the raw case, which may not even be an array
     * @return list<string>
     */
    private function propertyErrors(mixed $case, string $where): array
    {
        if (! is_array($case)) {
            return [];
        }

        $properties = $case['expect']['properties'] ?? $case['properties'] ?? [];
        if (! is_array($properties)) {
            return ["{$where}: expect.properties must be a list"];
        }

        $errors = [];
        foreach ($properties as $property) {
            if (! is_string($property) && ! is_array($property)) {
                $errors[] = "{$where}: a property must be a string or a mapping";

                continue;
            }

            $spec = PropertyChecker::normalise($property);
            $name = (string) $spec['type'];

            if ($name === '') {
                // `- steps_count: 3` (with a space) is a YAML mapping, not a
                // string, and arrives here with no type at all. Worth naming,
                // because it is invisible otherwise.
                $errors[] = "{$where}: a property has no type — a colon followed by a space makes it a YAML mapping";

                continue;
            }

            if (! PropertyRegistry::has($name)) {
                $errors[] = "{$where}: unknown property type \"{$name}\"";

                continue;
            }

            $arg = isset($spec['arg']) && is_string($spec['arg']) ? $spec['arg'] : null;
            if (($why = PropertyRegistry::get($name)?->arg->reject($arg, $name)) !== null) {
                $errors[] = "{$where}: {$why}";
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> parsed identities.yaml */
    public function identities(): array
    {
        if ($this->identities !== null) {
            return $this->identities;
        }
        $path = (string) config('agents.identities_manifest');
        $parsed = is_readable($path) ? Yaml::parseFile($path) : [];

        return $this->identities = is_array($parsed) ? $parsed : [];
    }

    /**
     * @return array<string, mixed> the identity record for the card's runs_on, plus 'key'
     */
    public function identityFor(SkillCard $card): array
    {
        $identity = $this->identities()['identities'][$card->runsOn] ?? null;
        if (! is_array($identity)) {
            throw new \RuntimeException("Unknown identity \"{$card->runsOn}\" for skill {$card->id}");
        }

        return $identity + ['key' => $card->runsOn];
    }

    public function coreAgentFor(SkillCard $card): string
    {
        if ($card->coreAgent !== '') {
            return $card->coreAgent;
        }
        $identity = $this->identities()['identities'][$card->runsOn] ?? [];

        return (string) ($identity['reports_to'] ?? 'atlas');
    }

    /** @return array<string, array{display_name: string, role: string}> */
    public function coreCharacterMeta(): array
    {
        return (array) ($this->identities()['core_characters'] ?? []);
    }

    /** @return list<string> */
    public function coreCharacters(): array
    {
        $configured = (array) config('agents.core_characters', []);

        return $configured !== [] ? array_values($configured) : array_keys($this->coreCharacterMeta());
    }

    /** @return list<string> every skill slug named in identities.yaml (the 43-skill roster) */
    public function rosterSkills(): array
    {
        $out = [];
        foreach ((array) ($this->identities()['identities'] ?? []) as $identity) {
            foreach ((array) ($identity['skills'] ?? []) as $slug) {
                $out[] = (string) $slug;
            }
        }

        return array_values(array_unique($out));
    }

    public function isPlannedSkill(string $slug): bool
    {
        return ! $this->has($slug) && in_array($slug, $this->rosterSkills(), true);
    }

    public function isKnownOrPlanned(string $slug): bool
    {
        return $this->has($slug) || in_array($slug, $this->rosterSkills(), true);
    }

    /** Display name for a card slug, from the card or (planned) a humanised slug. */
    public function displayNameFor(string $slug): string
    {
        $card = $this->get($slug);

        return $card ? $card->displayName : ucwords(str_replace('-', ' ', $slug));
    }

    /** @return array<string, mixed> parsed packages/brain/manifest.yaml */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        $path = (string) config('agents.brain_manifest');
        $parsed = is_readable($path) ? Yaml::parseFile($path) : [];

        return $this->manifest = is_array($parsed) ? $parsed : [];
    }

    /**
     * Manifest key (voice.tone) → "path#Section". Prefers BrainManifest::resolveKey
     * when the Brain stream's class is available, falls back to manifest.yaml keys.
     */
    public function resolveBrainKey(string $key): ?string
    {
        $class = 'App\\Services\\Brain\\BrainManifest';
        if (class_exists($class) && method_exists($class, 'resolveKey')) {
            try {
                $resolved = app($class)->resolveKey($key);
                if (is_string($resolved) && $resolved !== '') {
                    return $resolved;
                }
                if (is_array($resolved) && ! empty($resolved['path'])) {
                    return $resolved['path'].(! empty($resolved['section']) ? '#'.$resolved['section'] : '');
                }
            } catch (\Throwable) {
                // fall through to the file-based map
            }
        }
        $keys = (array) ($this->manifest()['keys'] ?? []);

        return isset($keys[$key]) ? (string) $keys[$key] : null;
    }

    /** @return array<string, mixed>|null the manifest entry (exact or pattern) for a brain path */
    public function manifestEntryFor(string $path): ?array
    {
        [$path] = SkillCard::splitPath($path);
        $entries = (array) ($this->manifest()['paths'] ?? []);
        foreach ($entries as $entry) {
            if (($entry['path'] ?? null) === $path) {
                return (array) $entry;
            }
        }
        $best = null;
        $bestLen = -1;
        foreach ($entries as $entry) {
            $pattern = (string) ($entry['path'] ?? '');
            if (empty($entry['pattern']) && ! str_contains($pattern, '**') && ! str_contains($pattern, '<')) {
                continue;
            }
            // "**" → any depth, "<slug>" → one path segment; everything else literal.
            $regex = preg_quote($pattern, '~');
            $regex = str_replace('\*\*', '.*', $regex);
            $regex = preg_replace('/<[a-z_]+>/', '[^/]+', $regex) ?? $regex;
            $regex = '~^'.$regex.'$~';
            if (preg_match($regex, $path) === 1 && strlen($pattern) > $bestLen) {
                $best = (array) $entry;
                $bestLen = strlen($pattern);
            }
        }

        return $best;
    }

    public function dataClassFor(string $path): string
    {
        $entry = $this->manifestEntryFor($path);

        return (string) (($entry['data_class'] ?? null) ?: 'internal');
    }

    /** @return list<string> question(s) the manifest asks for the given path/sections */
    public function questionsFor(string $path, array $sections = []): array
    {
        $entry = $this->manifestEntryFor($path);
        if ($entry === null) {
            return [];
        }
        $out = [];
        foreach ((array) ($entry['sections'] ?? []) as $section) {
            $name = (string) ($section['name'] ?? '');
            if ($sections !== [] && ! in_array($name, $sections, true)) {
                continue;
            }
            if (! empty($section['question'])) {
                $out[] = (string) $section['question'];
            }
        }

        return $out;
    }

    /** @return list<string> tool names the runtime knows (config/agents.php tool_costs) */
    public function knownTools(): array
    {
        $tools = array_keys((array) config('agents.tool_costs', []));

        return array_values(array_filter($tools, fn ($t) => $t !== 'default' && ! str_ends_with((string) $t, '.*')));
    }

    public function isKnownTool(string $tool): bool
    {
        if (in_array($tool, $this->knownTools(), true)) {
            return true;
        }
        foreach (array_keys((array) config('agents.tool_costs', [])) as $pattern) {
            if (str_ends_with((string) $pattern, '.*') && str_starts_with($tool, substr((string) $pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    public function schemaValidator(): SkillSchemaValidator
    {
        return $this->schema ??= SkillSchemaValidator::fromFile((string) config('agents.skill_card_schema'));
    }

    /** @return list<string> pillar keys in display order */
    public function pillars(): array
    {
        return ['sales', 'deals', 'marketing', 'operations', 'intelligence', 'customer', 'back_office', 'people', 'founder'];
    }

    /** @return list<array{key: string, label: string, order: int}> */
    public function pillarsMeta(): array
    {
        $labels = [
            'sales' => 'Sales', 'deals' => 'Deals', 'marketing' => 'Marketing', 'operations' => 'Operations',
            'intelligence' => 'Intelligence', 'customer' => 'Customer', 'back_office' => 'Back office',
            'people' => 'People', 'founder' => 'Founder',
        ];
        $out = [];
        foreach ($this->pillars() as $i => $key) {
            $out[] = ['key' => $key, 'label' => $labels[$key] ?? ucfirst($key), 'order' => ($i + 1) * 10];
        }

        return $out;
    }

    /** @return list<string> */
    private function folders(): array
    {
        if (! is_dir($this->root)) {
            return [];
        }
        $out = [];
        foreach (scandir($this->root) ?: [] as $name) {
            if ($name === '.' || $name === '..' || str_starts_with($name, '_') || str_starts_with($name, '.')) {
                continue;
            }
            $folder = $this->root.'/'.$name;
            if (is_dir($folder) && is_file($folder.'/card.yaml')) {
                $out[] = $folder;
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private function validateBrainPath(string $path, array $sections, string $where): array
    {
        $errors = [];
        if ($path === '') {
            return ["{$where}: empty brain path"];
        }
        $entry = $this->manifestEntryFor($path);
        if ($entry === null) {
            return ["{$where}: \"{$path}\" is not a path in the brain manifest"];
        }
        $known = array_map(fn ($s) => (string) ($s['name'] ?? ''), (array) ($entry['sections'] ?? []));
        if ($known === []) {
            return $errors;
        }
        foreach ($sections as $section) {
            if (! in_array($section, $known, true)) {
                $errors[] = "{$where}: section \"{$section}\" is not declared for {$path} in the brain manifest";
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function validateRef(string $ref, string $where): array
    {
        [$kind, $value] = array_pad(explode(':', $ref, 2), 2, '');
        $identities = $this->identities();

        return match ($kind) {
            'brain' => $this->manifestEntryFor($value) ? [] : ["{$where}: brain path \"{$value}\" is not in the manifest"],
            'principle' => $this->principleIds() === null || in_array($value, $this->principleIds(), true) ? [] : ["{$where}: unknown principle \"{$value}\""],
            'identity' => isset($identities['identities'][$value]) ? [] : ["{$where}: unknown identity \"{$value}\""],
            'product' => isset($identities['external_refs'][$value]) ? [] : ["{$where}: unknown product \"{$value}\""],
            default => ["{$where}: ref must start with brain:, principle:, identity: or product:"],
        };
    }

    /** @return list<string>|null null when the principles corpus is not on disk */
    private function principleIds(): ?array
    {
        if ($this->principleIds !== null) {
            return $this->principleIds ?: null;
        }
        $path = dirname($this->root).'/feature-packs/sales-crm/policies/sales-principles.yaml';
        if (! is_readable($path)) {
            $this->principleIds = [];

            return null;
        }
        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable) {
            $this->principleIds = [];

            return null;
        }
        $ids = [];
        foreach ((array) ($parsed['principles'] ?? []) as $principle) {
            if (! empty($principle['id'])) {
                $ids[] = (string) $principle['id'];
            }
        }
        $this->principleIds = $ids;

        return $ids ?: null;
    }

    /** @return list<string> keys a next step may reference under outputs.* */
    private function outputKeys(SkillCard $card): array
    {
        $keys = ['artifact_id', 'run_id', 'artifacts', 'next_steps', 'sequence_id'];
        foreach ($card->outputs as $output) {
            foreach (array_keys((array) ($output['schema']['properties'] ?? [])) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
