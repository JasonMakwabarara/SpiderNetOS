<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\FeaturePack;
use App\Models\PackEntitlement;
use App\Models\Skill;
use App\Models\SkillRelation;
use App\Models\Tenant;
use App\Models\TenantSkill;
use App\Services\Billing\PlanEntitlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The DB projection of the skill folders (plan D5): `skills` + `skill_relations`
 * seeded idempotently by card_hash, and the per-tenant views the cockpit
 * renders — the roster (forTenant) and the full-screen card (card).
 */
class SkillCatalogue
{
    public function __construct(
        private readonly SkillRegistry $registry,
    ) {}

    /**
     * Files → skills + skill_relations. Unchanged cards (same card_hash) are skipped.
     *
     * @return array{created: int, updated: int, unchanged: int, relations: int, slugs: list<string>}
     */
    public function seed(): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'relations' => 0, 'slugs' => []];

        foreach ($this->registry->all() as $slug => $card) {
            $hash = $card->contentHash();
            $existing = Skill::find($slug);
            if ($existing && $existing->card_hash === $hash) {
                $summary['unchanged']++;
                $summary['slugs'][] = $slug;

                continue;
            }

            DB::transaction(function () use ($card, $hash, $existing, &$summary): void {
                $attributes = [
                    'version' => $card->version,
                    'name' => $card->displayName,
                    'pillar' => $card->pillar,
                    'map_function' => Skill::PILLAR_FUNCTIONS[$card->pillar] ?? null,
                    'map_node' => (string) ($card->card['map_node'] ?? ''),
                    'runs_on' => $card->runsOn,
                    'core_agent' => $card->coreAgent,
                    'pack_id' => $card->packId,
                    'card' => $card->card,
                    'prompt_md' => $card->taskTemplate(),
                    'card_hash' => $hash,
                ];
                if ($existing) {
                    $existing->fill($attributes)->save();
                    $summary['updated']++;
                } else {
                    Skill::create(['slug' => $card->id] + $attributes);
                    $summary['created']++;
                }

                SkillRelation::where('from_slug', $card->id)->delete();
                foreach ($this->relationsFor($card) as $relation) {
                    SkillRelation::create($relation);
                    $summary['relations']++;
                }
            });
            $summary['slugs'][] = $slug;
        }

        return $summary;
    }

    /**
     * The roster: every card merged with the tenant's state and brain readiness.
     *
     * @return list<array<string, mixed>>
     */
    public function forTenant(string $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        $rows = TenantSkill::forTenant($tenantId)->get()->keyBy('skill_slug');
        $installedPacks = FeaturePack::where('tenant_id', $tenantId)->where('status', 'installed')->pluck('pack_id')->all();

        $out = [];
        foreach ($this->registry->all() as $slug => $card) {
            $row = $rows->get($slug);
            $files = $this->brainFiles($tenantId, $card);
            $identity = $this->identitySummary($card);
            $out[] = [
                'slug' => $slug,
                'name' => $card->displayName,
                'version' => $card->version,
                'pillar' => $card->pillar,
                'map_node' => (string) ($card->card['map_node'] ?? ''),
                'map_function' => Skill::PILLAR_FUNCTIONS[$card->pillar] ?? null,
                'core_agent' => $card->coreAgent,
                'identity' => $identity,
                'runs_on' => $card->runsOn,
                'pack_id' => $card->packId,
                'at_a_glance' => (string) ($card->card['at_a_glance'] ?? ''),
                'run_kind' => $card->runKind(),
                'mode' => $card->mode,
                'entry_path' => (string) ($card->run['entry_path'] ?? '/skills/'.$slug),
                'enabled' => (bool) ($row?->enabled ?? false),
                'installed' => $row !== null,
                'stage' => (string) ($row?->autonomy_level ?? $card->autonomy['default']),
                'stage_inherited' => $row === null,
                'clean_drafts_count' => (int) ($row?->clean_drafts_count ?? 0),
                'entitlement' => $this->entitlement($tenantId, $card, $installedPacks),
                'brain' => $this->brainSummary($files),
                'readiness' => $this->readiness($tenantId, $card),
                'intent_keywords' => $card->intentKeywords(),
                'tags' => array_values((array) ($card->card['tags'] ?? [])),
                'tenant_default' => $this->tenantDefaultStage($tenant),
            ];
        }

        return $out;
    }

    /**
     * The full card JSON the cockpit renders at /skills/{slug}.
     *
     * @return array<string, mixed>
     */
    public function card(string $tenantId, string $slug): array
    {
        $card = $this->registry->get($slug);
        if ($card === null) {
            throw new \InvalidArgumentException("Unknown skill \"{$slug}\".");
        }
        $tenant = Tenant::find($tenantId);
        $row = TenantSkill::forTenant($tenantId)->where('skill_slug', $slug)->first();
        $rows = TenantSkill::forTenant($tenantId)->get()->keyBy('skill_slug');
        $installedPacks = FeaturePack::where('tenant_id', $tenantId)->where('status', 'installed')->pluck('pack_id')->all();
        $files = $this->brainFiles($tenantId, $card);
        $entitlement = $this->entitlement($tenantId, $card, $installedPacks);
        $stage = (string) ($row?->autonomy_level ?? $card->autonomy['default']);
        $tenantDefault = $this->tenantDefaultStage($tenant);
        $stageCopy = (array) ($card->card['pipeline']['stage_copy'] ?? []);
        $identity = $this->identitySummary($card);
        $workspace = $row?->workspace_id ? AgentWorkspace::find($row->workspace_id) : null;

        $missingRequired = array_values(array_filter($files, fn (array $f) => $f['required'] && $f['status'] !== 'ready'));
        $runEnabled = $entitlement['entitled'] && $missingRequired === [];
        $runReason = null;
        if (! $entitlement['entitled']) {
            $runReason = 'Install the '.($card->packId ?? 'pack').' pack to run this skill.';
        } elseif ($missingRequired !== []) {
            $runReason = 'Fill '.implode(', ', array_map(fn (array $f) => $f['path'], $missingRequired)).' first — or run and answer the questions inline.';
        }

        $coreMeta = $this->registry->coreCharacterMeta()[$card->coreAgent] ?? [];

        return [
            'slug' => $slug,
            'name' => $card->displayName,
            'version' => $card->version,
            'pillar' => $card->pillar,
            'map_node' => (string) ($card->card['map_node'] ?? ''),
            'map_function' => Skill::PILLAR_FUNCTIONS[$card->pillar] ?? null,
            'mode' => $card->mode,
            'run_kind' => $card->runKind(),
            'intent_keywords' => $card->intentKeywords(),
            'tags' => array_values((array) ($card->card['tags'] ?? [])),

            // The ten card sections, in render order.
            'at_a_glance' => (string) ($card->card['at_a_glance'] ?? ''),
            'covers' => (string) ($card->card['covers'] ?? ''),
            'breaks_into' => array_values(array_map(function (array $item) use ($rows): array {
                $slug = isset($item['slug']) ? (string) $item['slug'] : null;

                return [
                    'slug' => $slug,
                    'name' => (string) ($item['name'] ?? ''),
                    'does' => (string) ($item['does'] ?? ''),
                    'available' => $slug === null ? null : $this->registry->has($slug),
                    'enabled' => $slug === null ? null : (bool) ($rows->get($slug)?->enabled ?? false),
                ];
            }, (array) ($card->card['breaks_into'] ?? []))),
            'builds_on' => array_values(array_map(function (array $item): array {
                $slug = isset($item['slug']) ? (string) $item['slug'] : null;

                return [
                    'slug' => $slug,
                    'ref' => isset($item['ref']) ? (string) $item['ref'] : null,
                    'name' => (string) ($item['name'] ?? ''),
                    'why' => (string) ($item['why'] ?? ''),
                    'available' => $slug === null ? true : $this->registry->has($slug),
                ];
            }, (array) ($card->card['builds_on'] ?? []))),
            'replaces' => array_values((array) ($card->card['replaces'] ?? [])),
            'pipeline' => [
                'stage' => $stage,
                'inherited' => $row === null,
                'tenant_default' => $tenantDefault,
                'allowed' => $card->autonomy['ladder'],
                'autonomous_allowed' => in_array('autonomous', $card->autonomy['ladder'], true) && $tenantDefault !== 'human_led',
                'default_level' => $card->autonomy['default'],
                'stage_copy' => $stageCopy,
                'promotion_gate' => (array) ($card->card['pipeline']['promotion_gate'] ?? []),
                'clean_drafts_count' => (int) ($row?->clean_drafts_count ?? 0),
            ],
            'your_role' => (string) ($stageCopy[$stage]['your_role'] ?? ''),
            'one_step_further' => $this->oneStepFurther($card),
            'brain' => [
                'files' => $files,
                'summary' => $this->brainSummary($files),
                'readiness' => $this->readiness($tenantId, $card),
                'writes' => $card->brain['writes'],
            ],
            'hands_off_to' => array_values(array_map(fn (array $item) => $this->handOff($item), $card->handsOffTo())),

            'inputs' => $card->inputs,
            'outputs' => array_values(array_map(fn (array $o) => array_diff_key($o, ['schema' => true]), $card->outputs)),
            'run_cta' => [
                'label' => $card->runKind() === 'interview' ? 'Start the interview' : ($card->runKind() === 'route' ? 'Open' : 'Run'),
                'kind' => $card->runKind(),
                'entry_path' => (string) ($card->run['entry_path'] ?? '/skills/'.$slug),
                'endpoint' => "/api/skills/{$slug}/run",
                'enabled' => $runEnabled,
                'reason' => $runReason,
                'missing_brain' => array_values(array_map(fn (array $f) => ['path' => $f['path'], 'section' => $f['first_missing_section'], 'question' => $f['ask_prompt']], $missingRequired)),
            ],
            'identity' => $identity + [
                'workspace' => $workspace ? ['id' => $workspace->id, 'slug' => $workspace->slug, 'status' => $workspace->status] : null,
            ],
            'core_agent' => [
                'slug' => $card->coreAgent,
                'display_name' => (string) ($coreMeta['display_name'] ?? ucfirst($card->coreAgent)),
                'role' => (string) ($coreMeta['role'] ?? ''),
            ],
            'entitlement' => $entitlement,
            'tenant_skill' => $row ? [
                'enabled' => (bool) $row->enabled,
                'autonomy_level' => $row->autonomy_level,
                'clean_drafts_count' => (int) $row->clean_drafts_count,
                'enabled_at' => $row->enabled_at?->toIso8601String(),
                'agent_id' => $row->agent_id,
                'workspace_id' => $row->workspace_id,
            ] : null,
            'recent_runs' => $this->recentRuns($tenantId, $slug),
            'cost_budget' => $card->costBudget,
            'approval' => $card->approval,
            'triggers' => $card->triggers,
            'tools' => $card->tools,
        ];
    }

    /**
     * Brain readiness per file the card reads: ready / partial / missing from
     * brain_files, with the manifest's question for the first missing section.
     *
     * @return list<array<string, mixed>>
     */
    public function brainFiles(string $tenantId, SkillCard $card): array
    {
        $entries = [];
        foreach ($card->brain['requires'] as $entry) {
            $entries[$entry['path']] = ['path' => $entry['path'], 'sections' => $entry['sections'], 'required' => true];
        }
        foreach ($card->brain['reads'] as $entry) {
            if (isset($entries[$entry['path']])) {
                $entries[$entry['path']]['sections'] = array_values(array_unique(array_merge($entries[$entry['path']]['sections'], $entry['sections'])));

                continue;
            }
            $entries[$entry['path']] = ['path' => $entry['path'], 'sections' => $entry['sections'], 'required' => false];
        }

        $out = [];
        foreach ($entries as $path => $entry) {
            $manifest = $this->registry->manifestEntryFor($path);
            $title = (string) ($manifest['title'] ?? Str::headline(basename($path, '.md')));
            if (str_contains($path, '*')) {
                $out[] = [
                    'path' => $path, 'title' => $title, 'status' => 'optional', 'required' => false,
                    'sections' => [], 'first_missing_section' => null, 'ask_prompt' => null,
                    'data_class' => (string) ($manifest['data_class'] ?? 'internal'),
                ];

                continue;
            }

            $file = DB::table('brain_files')->where('tenant_id', $tenantId)->where('path', $path)->first(['content', 'version', 'frontmatter']);
            $content = $file ? (string) $file->content : '';
            $manifestSections = [];
            foreach ((array) ($manifest['sections'] ?? []) as $section) {
                $manifestSections[(string) ($section['name'] ?? '')] = $section;
            }
            $wanted = $entry['sections'] !== [] ? $entry['sections'] : array_keys(array_filter($manifestSections, fn ($s) => ! empty($s['required'])));
            if ($wanted === [] && $manifestSections !== []) {
                $wanted = array_keys($manifestSections);
            }

            $sections = [];
            $firstMissing = null;
            foreach ($wanted as $name) {
                $body = $file ? SkillCard::section($content, $name) : null;
                $min = (int) ($manifestSections[$name]['min_chars'] ?? 0);
                $present = $body !== null && mb_strlen(trim($body)) >= $min;
                $sections[] = ['name' => $name, 'present' => $present];
                if (! $present && $firstMissing === null) {
                    $firstMissing = $name;
                }
            }

            $presentCount = count(array_filter($sections, fn ($s) => $s['present']));
            $status = match (true) {
                $file === null => 'missing',
                $sections === [] => trim(SkillCard::stripFrontmatter($content)) === '' ? 'missing' : 'ready',
                $presentCount === count($sections) => 'ready',
                $presentCount === 0 => 'missing',
                default => 'partial',
            };
            $question = null;
            if ($firstMissing !== null) {
                $question = (string) ($manifestSections[$firstMissing]['question'] ?? '') ?: null;
            } elseif ($file === null && $manifestSections !== []) {
                $question = (string) (reset($manifestSections)['question'] ?? '') ?: null;
            }

            $out[] = [
                'path' => $path,
                'title' => $title,
                'status' => $status,
                'required' => $entry['required'],
                'version' => $file ? (int) $file->version : null,
                'sections' => $sections,
                'first_missing_section' => $firstMissing,
                'ask_prompt' => $question,
                'data_class' => (string) ($manifest['data_class'] ?? 'internal'),
            ];
        }

        return $out;
    }

    /**
     * BrainGapAnalyzer's readiness for the card's required files when the
     * Brain stream's class exists; null otherwise.
     */
    public function readiness(string $tenantId, SkillCard $card): ?array
    {
        $class = 'App\\Services\\Brain\\BrainGapAnalyzer';
        if (! class_exists($class)) {
            return null;
        }
        try {
            $analyzer = app($class);
            $out = [];
            if (method_exists($analyzer, 'readiness')) {
                // BrainGapAnalyzer::readiness(string $tenantId): {pct, files[]} over the manifest's readiness_order.
                $out['readiness'] = $analyzer->readiness($tenantId);
            }
            if (method_exists($analyzer, 'gaps')) {
                // BrainGapAnalyzer::gaps(string $tenantId, list<{path, key?, sections?}>): [{path, section, question, reason}]
                $out['gaps'] = $analyzer->gaps($tenantId, (array) ($card->card['brain']['requires'] ?? $card->brain['requires']));
            }

            return $out === [] ? null : $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $installedPacks
     * @return array{required: bool, entitled: bool, pack_id: ?string, installed: bool, checkout_hint: ?string}
     */
    public function entitlement(string $tenantId, SkillCard $card, ?array $installedPacks = null): array
    {
        if ($card->packId === null) {
            return ['required' => false, 'entitled' => true, 'pack_id' => null, 'installed' => true, 'checkout_hint' => null];
        }
        $installedPacks ??= FeaturePack::where('tenant_id', $tenantId)->where('status', 'installed')->pluck('pack_id')->all();
        $installed = in_array($card->packId, $installedPacks, true);
        $entitled = $installed || PackEntitlement::forTenant($tenantId)->where('pack_id', $card->packId)->active()->exists();
        if (! $entitled) {
            try {
                $entitled = app(PlanEntitlementService::class)->packIncluded($tenantId, $card->packId);
            } catch (\Throwable) {
                $entitled = false;
            }
        }

        return [
            'required' => true,
            'entitled' => $entitled,
            'pack_id' => $card->packId,
            'installed' => $installed,
            'checkout_hint' => $entitled ? null : '/feature-packs?pack='.$card->packId,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function relationsFor(SkillCard $card): array
    {
        $rows = [];
        $position = 0;
        foreach ((array) ($card->card['breaks_into'] ?? []) as $item) {
            $slug = isset($item['slug']) && $this->registry->has((string) $item['slug']) ? (string) $item['slug'] : null;
            $rows[] = [
                'from_slug' => $card->id, 'relation' => SkillRelation::BREAKS_INTO,
                'to_slug' => $slug,
                'to_ref' => $slug === null ? 'capability:'.(isset($item['slug']) ? (string) $item['slug'] : Str::slug((string) ($item['name'] ?? ''))) : null,
                'meta' => ['name' => $item['name'] ?? null, 'does' => $item['does'] ?? null, 'planned' => $slug === null && isset($item['slug'])],
                'position' => $position++,
            ];
        }
        $position = 0;
        foreach ((array) ($card->card['builds_on'] ?? []) as $item) {
            $slug = isset($item['slug']) && $this->registry->has((string) $item['slug']) ? (string) $item['slug'] : null;
            $rows[] = [
                'from_slug' => $card->id, 'relation' => SkillRelation::BUILDS_ON,
                'to_slug' => $slug,
                'to_ref' => $slug === null ? (string) ($item['ref'] ?? ('skill:'.($item['slug'] ?? ''))) : null,
                'meta' => ['name' => $item['name'] ?? null, 'why' => $item['why'] ?? null],
                'position' => $position++,
            ];
        }
        $position = 0;
        foreach ((array) ($card->card['replaces'] ?? []) as $item) {
            $rows[] = [
                'from_slug' => $card->id, 'relation' => SkillRelation::REPLACES,
                'to_slug' => null,
                'to_ref' => 'role:'.Str::slug((string) ($item['what'] ?? '')),
                'meta' => ['what' => $item['what'] ?? null, 'cost' => $item['cost'] ?? null, 'kind' => $item['kind'] ?? null, 'note' => $item['note'] ?? null],
                'position' => $position++,
            ];
        }
        $position = 0;
        foreach ($card->handsOffTo() as $item) {
            $type = (string) ($item['type'] ?? 'skill');
            $slug = $type === 'skill' && isset($item['slug']) && $this->registry->has((string) $item['slug']) ? (string) $item['slug'] : null;
            $ref = $slug !== null ? null : ($type === 'skill' ? 'skill:'.($item['slug'] ?? '') : $type.':'.($item['ref'] ?? ''));
            $rows[] = [
                'from_slug' => $card->id, 'relation' => SkillRelation::HANDS_OFF_TO,
                'to_slug' => $slug,
                'to_ref' => $ref,
                'meta' => ['type' => $type, 'name' => $item['name'] ?? null, 'when' => $item['when'] ?? null, 'planned' => $type === 'skill' && $slug === null],
                'position' => $position++,
            ];
        }

        // The unique index is (from_slug, relation, to_slug, to_ref); drop exact duplicates.
        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $key = $row['relation'].'|'.($row['to_slug'] ?? '').'|'.($row['to_ref'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return $unique;
    }

    /** @return array<string, mixed> */
    private function oneStepFurther(SkillCard $card): array
    {
        $section = $card->oneStepFurther;
        $steps = [];
        foreach ($card->nextStepTemplates() as $step) {
            $skill = (string) ($step['skill'] ?? '');
            $steps[] = $step + [
                'skill_name' => $this->registry->displayNameFor($skill),
                'available' => $this->registry->has($skill),
                'planned' => $this->registry->isPlannedSkill($skill),
            ];
        }

        return [
            'summary' => (string) ($section['summary'] ?? ''),
            'allow_dynamic' => (bool) ($section['allow_dynamic'] ?? false),
            'steps' => $steps,
        ];
    }

    /** @return array<string, mixed> */
    private function handOff(array $item): array
    {
        $type = (string) ($item['type'] ?? 'skill');
        $slug = isset($item['slug']) ? (string) $item['slug'] : null;
        $ref = isset($item['ref']) ? (string) $item['ref'] : null;
        $identities = $this->registry->identities();
        $available = match ($type) {
            'skill' => $slug !== null && $this->registry->has($slug),
            'identity' => (($identities['identities'][$ref]['status'] ?? 'planned') === 'existing'),
            'product' => false,
            default => true,
        };
        $displayName = match ($type) {
            'skill' => $slug !== null ? $this->registry->displayNameFor($slug) : (string) ($item['name'] ?? ''),
            'identity' => (string) ($identities['identities'][$ref]['display_name'] ?? $item['name'] ?? $ref),
            'character' => (string) ($this->registry->coreCharacterMeta()[$ref]['display_name'] ?? $item['name'] ?? $ref),
            'product' => (string) ($identities['external_refs'][$ref]['display_name'] ?? $item['name'] ?? $ref),
            default => (string) ($item['name'] ?? $ref ?? ''),
        };

        return [
            'type' => $type,
            'slug' => $slug,
            'ref' => $ref,
            'name' => (string) ($item['name'] ?? $displayName),
            'display_name' => $displayName,
            'when' => (string) ($item['when'] ?? ''),
            'available' => $available,
            'planned' => $type === 'skill' && $slug !== null && $this->registry->isPlannedSkill($slug),
            'entry_path' => $type === 'skill' && $slug !== null && $this->registry->has($slug) ? '/skills/'.$slug : null,
        ];
    }

    /** @return array<string, mixed> */
    private function identitySummary(SkillCard $card): array
    {
        try {
            $identity = $this->registry->identityFor($card);
        } catch (\Throwable) {
            return ['key' => $card->runsOn, 'display_name' => $card->runsOn, 'agents_slug' => null, 'status' => 'unknown', 'reports_to' => $card->coreAgent];
        }

        return [
            'key' => (string) $identity['key'],
            'display_name' => (string) ($identity['display_name'] ?? $identity['key']),
            'agents_slug' => $identity['agents_slug'] ?? null,
            'status' => (string) ($identity['status'] ?? 'existing'),
            'reports_to' => (string) ($identity['reports_to'] ?? $card->coreAgent),
            'pack' => $identity['pack'] ?? null,
            'character_note' => $identity['character_note'] ?? null,
        ];
    }

    /** @param  list<array<string, mixed>>  $files */
    private function brainSummary(array $files): array
    {
        $required = array_values(array_filter($files, fn (array $f) => $f['required']));
        $ready = count(array_filter($required, fn (array $f) => $f['status'] === 'ready'));
        $total = count($required);

        return [
            'ready' => $ready,
            'total' => $total,
            'status' => $total === 0 ? 'ready' : ($ready === $total ? 'ready' : ($ready === 0 ? 'missing' : 'partial')),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentRuns(string $tenantId, string $slug): array
    {
        return AgentRun::forTenant($tenantId)
            ->where('skill_slug', $slug)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (AgentRun $run) => [
                'id' => $run->id,
                'status' => $run->status,
                'trigger_type' => $run->trigger_type,
                'cost_usd' => (float) $run->cost_usd,
                'created_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'next_steps' => $run->nextSteps(),
            ])
            ->all();
    }

    /** Tenant automation_level (manual|assisted|autonomous) expressed on the skill ladder. */
    private function tenantDefaultStage(?Tenant $tenant): string
    {
        return match ((string) ($tenant?->automation_level ?? 'manual')) {
            'autonomous' => 'autonomous',
            'assisted' => 'assisted',
            default => 'human_led',
        };
    }
}
