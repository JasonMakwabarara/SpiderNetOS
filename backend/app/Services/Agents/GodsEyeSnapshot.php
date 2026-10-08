<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Services\Skills\SkillRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * God's Eye (plan D6 §8): every agent in the business on one screen.
 *
 * Jason picked this over the alternatives: not a list of runs, not a log — a
 * live board of all agents at once, so the answer to "what is my business
 * doing right now" is a glance rather than a query.
 *
 * The organising unit is the CHARACTER, not the run. A hundred rows of
 * `agent_runs` tell you nothing; "Nexus is drafting, Prism is blocked on a
 * question, Sentinel has not run this week" is the shape of the answer. Every
 * skill resolves up through its identity to one of the six characters, so the
 * board is always six columns wide no matter how many skills are enabled.
 *
 * One query budget rule: the snapshot is polled, so it aggregates in SQL and
 * never loads a run collection into PHP to count it.
 */
class GodsEyeSnapshot
{
    /** Runs older than this are history, not "today". */
    public const WINDOW_HOURS = 24;

    /** The six, in the order the cockpit lays them out. */
    public const CHARACTERS = ['atlas', 'hannah', 'forge', 'sentinel', 'prism', 'nexus'];

    public function __construct(
        private readonly SkillRegistry $skills,
        private readonly AgentCircuitBreaker $breaker,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId, ?Carbon $now = null): array
    {
        $now ??= now();
        $since = $now->copy()->subHours(self::WINDOW_HOURS);

        $bySkill = $this->runsBySkill($tenantId, $since, $now);
        $characters = $this->characters($tenantId, $bySkill);

        return [
            'generated_at' => $now->toIso8601String(),
            'window_hours' => self::WINDOW_HOURS,
            'characters' => $characters,
            'totals' => $this->totals($characters, $tenantId, $since, $now),
            'live' => $this->live($tenantId),
            'needs_you' => $this->needsYou($tenantId),
            'breaker' => $this->breakerState($tenantId),
        ];
    }

    // ------------------------------------------------------------------ //
    //  Per-skill activity, aggregated in SQL
    // ------------------------------------------------------------------ //

    /** @return array<string, array<string, mixed>> keyed by skill slug */
    private function runsBySkill(string $tenantId, Carbon $since, Carbon $now): array
    {
        if (! Schema::hasTable('agent_runs')) {
            return [];
        }

        $rows = DB::table('agent_runs')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $since)
            ->selectRaw('skill_slug, count(*) as runs, sum(cost_usd) as cost')
            ->selectRaw("sum(case when status in ('queued', 'claimed', 'running') then 1 else 0 end) as active")
            ->selectRaw("sum(case when status in ('waiting_input', 'waiting_approval', 'blocked') then 1 else 0 end) as waiting")
            ->selectRaw("sum(case when status = 'succeeded' then 1 else 0 end) as succeeded")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw('max(created_at) as last_run_at')
            ->groupBy('skill_slug')
            ->get();

        $bySkill = [];
        foreach ($rows as $row) {
            $bySkill[(string) $row->skill_slug] = [
                'runs' => (int) $row->runs,
                'active' => (int) $row->active,
                'waiting' => (int) $row->waiting,
                'succeeded' => (int) $row->succeeded,
                'failed' => (int) $row->failed,
                'cost_usd' => round((float) $row->cost, 6),
                'last_run_at' => $row->last_run_at,
            ];
        }

        return $bySkill;
    }

    /**
     * Every enabled skill, grouped under the character it reports to.
     *
     * A character with nothing enabled still appears, greyed: "Sentinel has
     * nothing switched on" is a finding, and a board that hides its empty
     * columns hides exactly the thing worth noticing.
     *
     * @param  array<string, array<string, mixed>>  $bySkill
     * @return list<array<string, mixed>>
     */
    private function characters(string $tenantId, array $bySkill): array
    {
        $enabled = $this->enabledSkills($tenantId);

        $buckets = [];
        foreach (self::CHARACTERS as $slug) {
            $buckets[$slug] = [
                'slug' => $slug,
                'display_name' => ucfirst($slug),
                'skills' => [],
                'runs' => 0, 'active' => 0, 'waiting' => 0, 'failed' => 0, 'cost_usd' => 0.0,
                'last_run_at' => null,
            ];
        }

        foreach ($this->skills->all() as $slug => $card) {
            $character = $this->characterFor($card);
            if (! isset($buckets[$character])) {
                continue;
            }

            $activity = $bySkill[$slug] ?? null;
            $isEnabled = in_array($slug, $enabled, true);

            // A skill that is neither enabled nor active this window is noise
            // on a live board: it belongs in the roster, not here.
            if (! $isEnabled && $activity === null) {
                continue;
            }

            $buckets[$character]['skills'][] = [
                'slug' => $slug,
                'display_name' => $this->skills->displayNameFor($slug),
                'enabled' => $isEnabled,
                'runs' => $activity['runs'] ?? 0,
                'active' => $activity['active'] ?? 0,
                'waiting' => $activity['waiting'] ?? 0,
                'failed' => $activity['failed'] ?? 0,
                'cost_usd' => $activity['cost_usd'] ?? 0.0,
                'last_run_at' => $activity['last_run_at'] ?? null,
                'state' => $this->skillState($activity, $isEnabled),
            ];

            foreach (['runs', 'active', 'waiting', 'failed'] as $key) {
                $buckets[$character][$key] += $activity[$key] ?? 0;
            }
            $buckets[$character]['cost_usd'] += $activity['cost_usd'] ?? 0.0;

            if (($activity['last_run_at'] ?? null) !== null
                && ($buckets[$character]['last_run_at'] === null || $activity['last_run_at'] > $buckets[$character]['last_run_at'])) {
                $buckets[$character]['last_run_at'] = $activity['last_run_at'];
            }
        }

        foreach ($buckets as $slug => $bucket) {
            usort($buckets[$slug]['skills'], fn (array $a, array $b): int => [$b['active'], $b['waiting'], $b['runs'], $a['slug']]
                <=> [$a['active'], $a['waiting'], $a['runs'], $b['slug']]);
            $buckets[$slug]['cost_usd'] = round($bucket['cost_usd'], 6);
            $buckets[$slug]['state'] = $this->characterState($buckets[$slug]);
        }

        return array_values($buckets);
    }

    /** working | waiting | failing | idle | off */
    private function skillState(?array $activity, bool $enabled): string
    {
        if ($activity === null) {
            return $enabled ? 'idle' : 'off';
        }
        if (($activity['active'] ?? 0) > 0) {
            return 'working';
        }
        if (($activity['waiting'] ?? 0) > 0) {
            return 'waiting';
        }
        if (($activity['failed'] ?? 0) > 0 && ($activity['succeeded'] ?? 0) === 0) {
            return 'failing';
        }

        return $enabled ? 'idle' : 'off';
    }

    /**
     * A character is in the worst state any of its skills is in — derived from
     * the skills' own states, not from raw counts. A skill that failed twice
     * and then succeeded is not failing, and the column must not say it is
     * while the row beneath it says otherwise.
     */
    private function characterState(array $bucket): string
    {
        if ($bucket['skills'] === []) {
            return 'off';
        }

        $states = array_column($bucket['skills'], 'state');

        foreach (['working', 'waiting', 'failing', 'idle'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'off';
    }

    private function characterFor(mixed $card): string
    {
        try {
            $character = $this->skills->coreAgentFor($card);
        } catch (\Throwable) {
            return 'atlas';
        }

        return in_array($character, self::CHARACTERS, true) ? $character : 'atlas';
    }

    /** @return list<string> */
    private function enabledSkills(string $tenantId): array
    {
        if (! Schema::hasTable('tenant_skills')) {
            return [];
        }

        return DB::table('tenant_skills')
            ->where('tenant_id', $tenantId)->where('enabled', true)
            ->pluck('skill_slug')->map(fn ($s): string => (string) $s)->all();
    }

    // ------------------------------------------------------------------ //
    //  The wall's headline numbers
    // ------------------------------------------------------------------ //

    /** @param  list<array<string, mixed>>  $characters */
    private function totals(array $characters, string $tenantId, Carbon $since, Carbon $now): array
    {
        $totals = ['runs' => 0, 'active' => 0, 'waiting' => 0, 'failed' => 0, 'cost_usd' => 0.0];

        foreach ($characters as $character) {
            foreach (['runs', 'active', 'waiting', 'failed'] as $key) {
                $totals[$key] += $character[$key];
            }
            $totals['cost_usd'] += $character['cost_usd'];
        }

        $totals['cost_usd'] = round($totals['cost_usd'], 6);
        $totals['characters_working'] = count(array_filter($characters, fn (array $c): bool => $c['state'] === 'working'));
        $totals['characters_idle'] = count(array_filter($characters, fn (array $c): bool => in_array($c['state'], ['idle', 'off'], true)));
        $totals['drafts'] = Schema::hasTable('agent_artifacts')
            ? DB::table('agent_artifacts')->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $since)->where('created_at', '<=', $now)->count()
            : 0;

        return $totals;
    }

    /**
     * What is on the wire right now — the runs a viewer would expect to see
     * moving. Capped, because a live board that scrolls is not a live board.
     *
     * @return list<array<string, mixed>>
     */
    private function live(string $tenantId, int $limit = 12): array
    {
        if (! Schema::hasTable('agent_runs')) {
            return [];
        }

        return DB::table('agent_runs')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['queued', 'claimed', 'running'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'skill_slug', 'status', 'mode', 'trigger_type', 'started_at', 'created_at'])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'skill_slug' => (string) $row->skill_slug,
                'display_name' => $this->skills->displayNameFor((string) $row->skill_slug),
                'status' => (string) $row->status,
                'trigger' => (string) $row->trigger_type,
                'started_at' => $row->started_at ?? $row->created_at,
            ])
            ->values()->all();
    }

    /**
     * The runs that have stopped and are waiting on a person. This is the only
     * part of the board that is actionable, so it is separate from the rest.
     *
     * @return list<array<string, mixed>>
     */
    private function needsYou(string $tenantId, int $limit = 12): array
    {
        if (! Schema::hasTable('agent_runs')) {
            return [];
        }

        return DB::table('agent_runs')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [AgentRun::STATUS_WAITING_INPUT, AgentRun::STATUS_WAITING_APPROVAL, AgentRun::STATUS_BLOCKED])
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'skill_slug', 'status', 'questions', 'created_at'])
            ->map(function ($row): array {
                $questions = is_string($row->questions) ? (json_decode($row->questions, true) ?: []) : (array) $row->questions;
                $first = $questions[0] ?? null;

                return [
                    'id' => (string) $row->id,
                    'skill_slug' => (string) $row->skill_slug,
                    'display_name' => $this->skills->displayNameFor((string) $row->skill_slug),
                    'status' => (string) $row->status,
                    'question' => is_array($first) ? ($first['question'] ?? null) : (is_string($first) ? $first : null),
                    'waiting_since' => $row->created_at,
                    'path' => '/agents/runs/'.$row->id,
                ];
            })
            ->values()->all();
    }

    /**
     * Whether anything is paused. A board that shows six idle characters
     * without saying "you pressed stop" is actively misleading.
     *
     * @return array<string, mixed>
     */
    private function breakerState(string $tenantId): array
    {
        try {
            $states = $this->breaker->states($tenantId);
        } catch (\Throwable) {
            return ['paused' => false, 'scopes' => []];
        }

        // states() returns every row it has ever written, including resumed
        // ones. Only a live pause or demotion belongs on the wall.
        $scopes = [];
        foreach ($states as $state) {
            if (! is_array($state) || ! in_array($state['state'] ?? '', ['paused', 'demoted'], true)) {
                continue;
            }
            $scopes[] = [
                'scope' => $state['scope'] ?? null,
                'scope_id' => $state['scope_id'] ?? null,
                'state' => $state['state'],
                'reason' => $state['reason'] ?? null,
                'tripped_at' => $state['tripped_at'] ?? null,
            ];
        }

        return [
            'paused' => $scopes !== [],
            'scopes' => $scopes,
        ];
    }
}
