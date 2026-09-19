<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\Skill;
use App\Models\SkillRelation;
use App\Models\Tenant;
use App\Models\TenantSkill;
use App\Models\User;
use App\Services\Brain\BrainGapAnalyzer;
use App\Services\FeatureFlag;
use App\Services\Skills\SkillCard;
use App\Services\Skills\SkillCatalogue;
use App\Services\Skills\SkillRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The business map (plan D6-C): the tenant's business as a core (the three
 * brains) ringed by the nine pillars, each carrying nodes coloured by status.
 *
 * Nodes
 *  - skill node: every catalogue skill sharing a `map_node` ("Sales › Outreach
 *    writing") is one node; id = the slug of that label (sales-outreach-writing).
 *    Processes whose `skill_slug` names one of its skills sit on it.
 *  - system node: a business_systems row, id = its uuid, placed on the first
 *    pillar mapping to its function. Emitted when no catalogue skill covers the
 *    function, or when the system still has processes no skill does — so the
 *    founder's own processes never drop off the map. Its processes are the
 *    ones without a (known) skill.
 *
 * Status (rank live > assisted > human > missing; a node takes its best):
 *  - skill: not enabled → missing; human_led → human; assisted|shadow →
 *    assisted; autonomous → live when every required brain file is ready
 *    (or the brain is off), else assisted.
 *  - process: agent-owned with a runbook and no open escalation → live;
 *    agent-owned otherwise → assisted; team/founder → human.
 *
 * Pillar → business_systems.function: Skill::PILLAR_FUNCTIONS (sales, deals →
 * sales; marketing; operations, customer → operations; intelligence, founder →
 * management; back_office → finance; people → recruitment). Ordering is
 * deterministic: pillars in catalogue order, skill nodes by label then system
 * nodes by name, skills by slug, brain files by path.
 */
final class BusinessMapService
{
    public const STATUS_LIVE = 'live';

    public const STATUS_ASSISTED = 'assisted';

    public const STATUS_HUMAN = 'human';

    public const STATUS_MISSING = 'missing';

    public const STATUSES = [self::STATUS_LIVE, self::STATUS_ASSISTED, self::STATUS_HUMAN, self::STATUS_MISSING];

    /** business_systems.function → the pillar a system node sits under (first pillar in order mapping to it). */
    public const FUNCTION_PILLARS = [
        'sales' => 'sales',
        'marketing' => 'marketing',
        'operations' => 'operations',
        'management' => 'intelligence',
        'finance' => 'back_office',
        'recruitment' => 'people',
        'retraining' => 'people',
    ];

    private const STATUS_RANK = [
        self::STATUS_MISSING => 0,
        self::STATUS_HUMAN => 1,
        self::STATUS_ASSISTED => 2,
        self::STATUS_LIVE => 3,
    ];

    private const BRAIN_RANK = ['missing' => 0, 'partial' => 1, 'ready' => 2];

    private const SKILL_FEEDBACK_SIGNALS = ['skill_feedback_positive', 'skill_feedback_negative', 'skill_feedback_neutral'];

    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly SkillCatalogue $catalogue,
    ) {}

    /**
     * GET /api/map
     *
     * @return array{core: array<string, mixed>, pillars: list<array<string, mixed>>}
     */
    public function map(string $tenantId, ?User $viewer = null): array
    {
        $context = $this->context($tenantId);

        $byPillar = [];
        foreach ($context['nodes'] as $node) {
            $byPillar[$node['pillar']][] = $this->publicNode($node);
        }

        $pillars = [];
        foreach ($this->registry->pillarsMeta() as $meta) {
            $nodes = $byPillar[$meta['key']] ?? [];
            $pillars[] = $meta + ['status' => $this->pillarStatus($nodes), 'nodes' => $nodes];
        }

        return [
            'core' => $this->core($tenantId, $viewer, $context['brain_on']),
            'pillars' => $pillars,
        ];
    }

    /**
     * GET /api/map/nodes/{id}: the node plus its processes (systemization/map
     * shape), its skills' last ten runs and full brain readiness. Null when the
     * id is not a node of this tenant's map.
     *
     * @return array<string, mixed>|null
     */
    public function node(string $tenantId, string $nodeId): ?array
    {
        if (Str::isUuid($nodeId)) {
            // Only a uuid can be a system row; confirm tenancy before building anything.
            if (! BusinessSystem::query()->where('tenant_id', $tenantId)->whereKey($nodeId)->exists()) {
                return null;
            }
        }

        $context = $this->context($tenantId);
        $node = $context['nodes'][$nodeId] ?? null;
        if ($node === null) {
            return null;
        }

        $processes = BusinessProcess::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $node['process_ids'])
            ->withExists(['sops as has_published_sop' => fn ($q) => $q->where('status', 'published')])
            ->orderBy('position')
            ->orderBy('effort_size')
            ->orderBy('id')
            ->get();

        $runs = $node['skill_slugs'] === [] ? [] : AgentRun::forTenant($tenantId)
            ->whereIn('skill_slug', $node['skill_slugs'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (AgentRun $run): array => [
                'id' => (string) $run->id,
                'skill_slug' => $run->skill_slug,
                'status' => $run->status,
                'mode' => $run->mode,
                'trigger_type' => $run->trigger_type,
                'cost_usd' => (float) $run->cost_usd,
                'created_at' => $run->created_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])
            ->all();

        $meta = collect($this->registry->pillarsMeta())->firstWhere('key', $node['pillar']);
        $public = $this->publicNode($node);

        // Same keys as the map node; `runs` becomes the run list and `brain_files`
        // the full readiness rows, with the map's run summary kept alongside.
        return array_replace($public, [
            'runs' => $runs,
            'brain_files' => array_values($node['brain_files']),
        ]) + [
            'pillar' => ['key' => $node['pillar'], 'label' => (string) ($meta['label'] ?? Str::headline($node['pillar']))],
            'processes' => $processes,
            'runs_summary' => $public['runs'],
        ];
    }

    /**
     * Catalogue skills per business_systems.function, with the tenant's state —
     * the `skills[]` GET /api/systemization/map adds to each system.
     *
     * @return array<string, list<array{slug: string, name: string, pillar: string, node_id: string, enabled: bool, stage: string}>>
     */
    public function skillsByFunction(string $tenantId): array
    {
        $rows = TenantSkill::forTenant($tenantId)->get()->keyBy('skill_slug');
        $out = [];
        foreach ($this->skills() as $slug => $skill) {
            $function = $skill->mapFunction();
            if ($function === null || $function === '') {
                continue;
            }
            $row = $rows->get($slug);
            $out[$function][] = [
                'slug' => (string) $slug,
                'name' => (string) $skill->name,
                'pillar' => (string) $skill->pillar,
                'node_id' => $this->skillNodeId($skill),
                'enabled' => (bool) ($row?->enabled ?? false),
                'stage' => $this->stageOf($skill, $row),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ //
    //  assembly
    // ------------------------------------------------------------------ //

    /**
     * @return array{brain_on: bool, nodes: array<string, array<string, mixed>>}
     */
    private function context(string $tenantId): array
    {
        $brainOn = FeatureFlag::on('brain.enabled', $tenantId);
        $skills = $this->skills();
        $slugs = array_map('strval', $skills->keys()->all());
        $tenantSkills = TenantSkill::forTenant($tenantId)->whereIn('skill_slug', $slugs)->get()->keyBy('skill_slug');

        $systems = BusinessSystem::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
        $processes = BusinessProcess::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('position')
            ->orderBy('effort_size')
            ->orderBy('id')
            ->get();

        // Skill nodes: skills grouped by their map_node.
        $nodes = [];
        $nodeOfSkill = [];
        foreach ($skills as $slug => $skill) {
            if (! in_array($skill->pillar, Skill::PILLARS, true)) {
                continue;
            }
            $id = $this->skillNodeId($skill);
            $nodeOfSkill[$slug] = $id;
            $nodes[$id] ??= $this->blankNode($id, 'skill', (string) $skill->pillar, $this->nodeLabel($skill));
            $nodes[$id]['skill_slugs'][] = (string) $slug;
        }

        $linked = [];
        $unlinkedBySystem = [];
        foreach ($processes as $process) {
            $skillSlug = (string) ($process->skill_slug ?? '');
            if ($skillSlug !== '' && isset($nodeOfSkill[$skillSlug])) {
                $linked[$nodeOfSkill[$skillSlug]][] = $process;
            } else {
                $unlinkedBySystem[(string) $process->system_id][] = $process;
            }
        }

        $coveredFunctions = [];
        foreach ($skills as $slug => $skill) {
            if (isset($nodeOfSkill[$slug]) && ($function = $skill->mapFunction())) {
                $coveredFunctions[$function] = true;
            }
        }

        // System nodes: systems no skill covers, or with processes no skill does.
        $systemNodes = [];
        foreach ($systems as $system) {
            $pillar = self::FUNCTION_PILLARS[(string) $system->function] ?? null;
            $unlinked = $unlinkedBySystem[(string) $system->id] ?? [];
            if ($pillar === null || (isset($coveredFunctions[(string) $system->function]) && $unlinked === [])) {
                continue;
            }
            $id = (string) $system->id;
            $systemNodes[$id] = array_replace($this->blankNode($id, 'system', $pillar, (string) $system->name), ['system_id' => $id]);
            $linked[$id] = $unlinked;
        }

        uasort($nodes, fn (array $a, array $b): int => [mb_strtolower($a['label']), $a['id']] <=> [mb_strtolower($b['label']), $b['id']]);
        $nodes += $systemNodes;

        $buildsOn = $this->buildsOnEdges($skills);
        $runStats = $this->runStats($tenantId, $slugs);
        $brainCache = [];

        foreach ($nodes as $id => &$node) {
            $status = self::STATUS_MISSING;
            $agentSkill = false;
            $skillsOut = [];
            $brainFiles = [];
            sort($node['skill_slugs']);

            foreach ($node['skill_slugs'] as $slug) {
                $skill = $skills->get($slug);
                $row = $tenantSkills->get($slug);
                $enabled = (bool) ($row?->enabled ?? false);
                $stage = $this->stageOf($skill, $row);

                $files = $brainOn ? ($brainCache[$slug] ??= $this->brainFiles($tenantId, $skill)) : [];
                $brainReady = true;
                foreach ($files as $file) {
                    if (! empty($file['required']) && ! in_array($file['status'], ['ready', 'optional'], true)) {
                        $brainReady = false;
                    }
                    $brainFiles[$file['path']] = $this->mergeBrainFile($brainFiles[$file['path']] ?? null, $file);
                }

                $status = $this->best($status, $this->skillStatus($enabled, $stage, $brainReady));
                $agentSkill = $agentSkill || ($enabled && $stage !== TenantSkill::AUTONOMY_HUMAN_LED);
                $skillsOut[] = ['slug' => $slug, 'name' => (string) $skill->name, 'enabled' => $enabled, 'stage' => $stage];
            }

            /** @var list<BusinessProcess> $nodeProcesses */
            $nodeProcesses = $linked[$id] ?? [];
            foreach ($nodeProcesses as $process) {
                $status = $this->best($status, $this->processStatus($process));
            }

            $edges = [];
            foreach ($node['skill_slugs'] as $slug) {
                foreach ($buildsOn[$slug] ?? [] as $target) {
                    $targetNode = $nodeOfSkill[$target] ?? null;
                    if ($targetNode !== null && $targetNode !== $id) {
                        $edges[$targetNode] = true;
                    }
                }
            }
            $edges = array_keys($edges);
            sort($edges);
            ksort($brainFiles);

            $node['status'] = $status;
            $node['owner_type'] = $agentSkill ? 'agent' : $this->dominantOwner($nodeProcesses);
            $node['skills'] = $skillsOut;
            $node['brain_files'] = $brainFiles;
            $node['builds_on'] = $edges;
            $node['runs'] = $node['kind'] === 'skill'
                ? $this->skillRuns($tenantId, $node['skill_slugs'], $runStats)
                : $this->processRuns($tenantId, $nodeProcesses);
            $node['process_ids'] = array_map(fn (BusinessProcess $p): string => (string) $p->id, $nodeProcesses);
            $node['process_count'] = count($nodeProcesses);
        }
        unset($node);

        return ['brain_on' => $brainOn, 'nodes' => $nodes];
    }

    /**
     * The catalogue: the seeded `skills` table, or the card files when the
     * table has not been seeded yet (fresh dev database).
     *
     * @return Collection<string, Skill>
     */
    private function skills(): Collection
    {
        $rows = Skill::query()->orderBy('slug')->get()->keyBy('slug');
        if ($rows->isNotEmpty()) {
            return $rows;
        }

        return collect($this->registry->all())
            ->map(fn (SkillCard $card): Skill => new Skill([
                'slug' => $card->id,
                'version' => $card->version,
                'name' => $card->displayName,
                'pillar' => $card->pillar,
                'map_function' => Skill::PILLAR_FUNCTIONS[$card->pillar] ?? null,
                'map_node' => (string) ($card->card['map_node'] ?? ''),
                'runs_on' => $card->runsOn,
                'core_agent' => $card->coreAgent,
                'pack_id' => $card->packId,
                'card' => $card->card,
            ]))
            ->sortKeys();
    }

    /**
     * builds_on edges between catalogue skills: skill_relations for seeded
     * rows, the card's builds_on[] otherwise.
     *
     * @param  Collection<string, Skill>  $skills
     * @return array<string, list<string>>
     */
    private function buildsOnEdges(Collection $skills): array
    {
        $edges = [];
        if ($skills->isNotEmpty() && $skills->first()->exists) {
            SkillRelation::query()
                ->where('relation', SkillRelation::BUILDS_ON)
                ->whereIn('from_slug', $skills->keys()->all())
                ->whereNotNull('to_slug')
                ->orderBy('from_slug')
                ->orderBy('position')
                ->get(['from_slug', 'to_slug'])
                ->each(function (SkillRelation $relation) use (&$edges): void {
                    $edges[(string) $relation->from_slug][] = (string) $relation->to_slug;
                });

            return $edges;
        }

        foreach ($skills as $slug => $skill) {
            foreach ((array) (($skill->card ?? [])['builds_on'] ?? []) as $item) {
                if (is_array($item) && ! empty($item['slug'])) {
                    $edges[(string) $slug][] = (string) $item['slug'];
                }
            }
        }

        return $edges;
    }

    /** @return list<array<string, mixed>> SkillCatalogue::brainFiles for the skill's card */
    private function brainFiles(string $tenantId, Skill $skill): array
    {
        try {
            $card = $this->registry->get((string) $skill->slug) ?? SkillCard::fromArray(
                ['id' => $skill->slug] + (array) ($skill->card ?? []),
                $this->registry->root().'/'.$skill->slug,
                fn (string $key): ?string => $this->registry->resolveBrainKey($key),
            );

            return $this->catalogue->brainFiles($tenantId, $card);
        } catch (\Throwable $e) {
            Log::warning('business_map.brain_files_failed', ['skill' => $skill->slug, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function mergeBrainFile(?array $existing, array $file): array
    {
        if ($existing === null) {
            return $file;
        }
        $required = ! empty($existing['required']) || ! empty($file['required']);
        if ($existing['status'] === 'optional') {
            return ['required' => $required] + $file;
        }
        if ($file['status'] !== 'optional'
            && (self::BRAIN_RANK[$file['status']] ?? 0) < (self::BRAIN_RANK[$existing['status']] ?? 0)) {
            return ['required' => $required] + $file;
        }

        return ['required' => $required] + $existing;
    }

    /**
     * @param  list<string>  $slugs
     * @return array{counts: array<string, int>, latest: array<string, string>}
     */
    private function runStats(string $tenantId, array $slugs): array
    {
        if ($slugs === []) {
            return ['counts' => [], 'latest' => []];
        }

        $counts = AgentRun::forTenant($tenantId)
            ->whereIn('skill_slug', $slugs)
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('skill_slug, count(*) as aggregate')
            ->groupBy('skill_slug')
            ->pluck('aggregate', 'skill_slug')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $latest = AgentRun::forTenant($tenantId)
            ->whereIn('skill_slug', $slugs)
            ->selectRaw('skill_slug, max(created_at) as last_at')
            ->groupBy('skill_slug')
            ->pluck('last_at', 'skill_slug')
            ->map(fn ($at): string => (string) $at)
            ->all();

        return ['counts' => $counts, 'latest' => $latest];
    }

    /**
     * @param  list<string>  $slugs
     * @param  array{counts: array<string, int>, latest: array<string, string>}  $stats
     * @return array{last_at: ?string, last_status: ?string, count_7d: int}
     */
    private function skillRuns(string $tenantId, array $slugs, array $stats): array
    {
        $count = 0;
        $hasRuns = false;
        foreach ($slugs as $slug) {
            $count += $stats['counts'][$slug] ?? 0;
            $hasRuns = $hasRuns || isset($stats['latest'][$slug]);
        }

        $last = $hasRuns ? AgentRun::forTenant($tenantId)
            ->whereIn('skill_slug', $slugs)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'status', 'created_at']) : null;

        return [
            'last_at' => $last?->created_at?->toIso8601String(),
            'last_status' => $last?->status,
            'count_7d' => $count,
        ];
    }

    /**
     * A system node's runs are its processes' runbook executions.
     *
     * @param  list<BusinessProcess>  $processes
     * @return array{last_at: ?string, last_status: ?string, count_7d: int}
     */
    private function processRuns(string $tenantId, array $processes): array
    {
        $last = null;
        foreach ($processes as $process) {
            if ($process->last_run_at !== null && ($last === null || $process->last_run_at->gt($last->last_run_at))) {
                $last = $process;
            }
        }

        $flowIds = array_values(array_unique(array_filter(array_map(
            fn (BusinessProcess $p): ?string => $p->flow_id ? (string) $p->flow_id : null,
            $processes,
        ))));
        $count = $flowIds === [] ? 0 : DB::table('flow_executions')
            ->where('tenant_id', $tenantId)
            ->whereIn('flow_id', $flowIds)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            'last_at' => $last?->last_run_at?->toIso8601String(),
            'last_status' => $last?->last_run_status,
            'count_7d' => $count,
        ];
    }

    /** @return array<string, mixed> */
    private function core(string $tenantId, ?User $viewer, bool $brainOn): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $owner = User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['admin', 'super_admin'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('name');

        return [
            'name' => (string) ($tenant?->name ?? 'Your business'),
            'three_brains' => [
                'knowledge' => $this->knowledge($tenantId, $brainOn),
                'operating' => [
                    'workspaces_active' => AgentWorkspace::forTenant($tenantId)->runnable()->count(),
                    'runs_today' => AgentRun::forTenant($tenantId)->where('created_at', '>=', Carbon::now()->startOfDay())->count(),
                ],
                'learning' => $this->learning($tenantId),
            ],
            'owner' => ['name' => (string) ($owner ?? $viewer?->name ?? '')],
        ];
    }

    /** @return array{pct: ?int, files_filled: int, files_total: int, enabled: bool} */
    private function knowledge(string $tenantId, bool $brainOn): array
    {
        if (! $brainOn) {
            return ['pct' => null, 'files_filled' => 0, 'files_total' => 0, 'enabled' => false];
        }

        try {
            $readiness = app(BrainGapAnalyzer::class)->readiness($tenantId);
        } catch (\Throwable $e) {
            Log::warning('business_map.brain_readiness_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return ['pct' => null, 'files_filled' => 0, 'files_total' => 0, 'enabled' => true];
        }

        $files = (array) ($readiness['files'] ?? []);

        return [
            'pct' => (int) ($readiness['pct'] ?? 0),
            'files_filled' => count(array_filter($files, fn ($f): bool => ($f['status'] ?? null) === BrainGapAnalyzer::STATUS_FILLED)),
            'files_total' => count($files),
            'enabled' => true,
        ];
    }

    /**
     * Outcomes: the D8 outcome ledger (skill_outcomes) when it exists, else the
     * skill feedback the cards record today. Experiments: open rows of the D8
     * experiments registry when it exists, else 0.
     *
     * @return array{outcomes_30d: int, experiments_open: int}
     */
    private function learning(string $tenantId): array
    {
        $since = now()->subDays(30);
        $outcomes = 0;
        $experiments = 0;

        try {
            $outcomes = Schema::hasTable('skill_outcomes')
                ? DB::table('skill_outcomes')->where('tenant_id', $tenantId)->where('created_at', '>=', $since)->count()
                : DB::table('tenant_pack_signals')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('signal_type', self::SKILL_FEEDBACK_SIGNALS)
                    ->where('created_at', '>=', $since)
                    ->count();

            if (Schema::hasTable('experiments') && Schema::hasColumn('experiments', 'status')) {
                $experiments = DB::table('experiments')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', ['open', 'running'])
                    ->count();
            }
        } catch (\Throwable $e) {
            Log::warning('business_map.learning_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
        }

        return ['outcomes_30d' => $outcomes, 'experiments_open' => $experiments];
    }

    // ------------------------------------------------------------------ //
    //  rules
    // ------------------------------------------------------------------ //

    private function skillStatus(bool $enabled, string $stage, bool $brainReady): string
    {
        if (! $enabled) {
            return self::STATUS_MISSING;
        }

        return match ($stage) {
            TenantSkill::AUTONOMY_AUTONOMOUS => $brainReady ? self::STATUS_LIVE : self::STATUS_ASSISTED,
            TenantSkill::AUTONOMY_ASSISTED, TenantSkill::AUTONOMY_SHADOW => self::STATUS_ASSISTED,
            default => self::STATUS_HUMAN,
        };
    }

    private function processStatus(BusinessProcess $process): string
    {
        if ($process->owner_type === 'agent') {
            return $process->flow_id && ! $process->needs_attention ? self::STATUS_LIVE : self::STATUS_ASSISTED;
        }

        return self::STATUS_HUMAN;
    }

    /**
     * The owner kind holding the most processes; ties and empty nodes go to the
     * founder (whatever nobody else owns, the founder does).
     *
     * @param  list<BusinessProcess>  $processes
     */
    private function dominantOwner(array $processes): string
    {
        $counts = ['founder' => 0, 'team' => 0, 'agent' => 0];
        foreach ($processes as $process) {
            $type = (string) $process->owner_type;
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
        }
        $best = 'founder';
        foreach (['team', 'agent'] as $type) {
            if ($counts[$type] > $counts[$best]) {
                $best = $type;
            }
        }

        return $best;
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function pillarStatus(array $nodes): string
    {
        if ($nodes === []) {
            return self::STATUS_MISSING;
        }
        $statuses = array_values(array_unique(array_column($nodes, 'status')));
        if (count($statuses) === 1) {
            return $statuses[0];
        }
        if (array_intersect($statuses, [self::STATUS_LIVE, self::STATUS_ASSISTED]) !== []) {
            return self::STATUS_ASSISTED;
        }

        return in_array(self::STATUS_HUMAN, $statuses, true) ? self::STATUS_HUMAN : self::STATUS_MISSING;
    }

    private function best(string $a, string $b): string
    {
        return self::STATUS_RANK[$b] > self::STATUS_RANK[$a] ? $b : $a;
    }

    private function stageOf(Skill $skill, ?TenantSkill $row): string
    {
        return (string) ($row?->autonomy_level
            ?? (($skill->card ?? [])['pipeline']['default_level'] ?? null)
            ?? TenantSkill::AUTONOMY_HUMAN_LED);
    }

    public function skillNodeId(Skill $skill): string
    {
        $basis = trim((string) $skill->map_node) !== '' ? (string) $skill->map_node : $skill->pillar.' '.$skill->slug;
        $id = Str::slug(str_replace(['›', '>', '/'], ' ', $basis));

        return $id !== '' && ! Str::isUuid($id) ? $id : 'skill-'.$skill->slug;
    }

    private function nodeLabel(Skill $skill): string
    {
        $segments = array_values(array_filter(array_map('trim', explode('›', (string) $skill->map_node)), fn (string $s): bool => $s !== ''));

        return $segments !== [] ? end($segments) : (string) $skill->name;
    }

    /** @return array<string, mixed> */
    private function blankNode(string $id, string $kind, string $pillar, string $label): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'pillar' => $pillar,
            'label' => $label,
            'system_id' => null,
            'skill_slugs' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function publicNode(array $node): array
    {
        return [
            'id' => $node['id'],
            'label' => $node['label'],
            'kind' => $node['kind'],
            'status' => $node['status'],
            'owner_type' => $node['owner_type'],
            'skills' => $node['skills'],
            'brain_files' => array_values(array_map(
                fn (array $file): array => ['path' => (string) $file['path'], 'status' => (string) $file['status']],
                $node['brain_files'],
            )),
            'builds_on' => $node['builds_on'],
            'runs' => $node['runs'],
            'process_count' => $node['process_count'],
            'system_id' => $node['system_id'],
        ];
    }
}
