<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\TenantSkill;
use App\Services\Revisions\RevisionRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The trust section of the Monday letter (plan D8 #11): one row per skill that
 * did anything this week — runs, how many drafts went out untouched, how many
 * the user edited, how many were rejected, breaker trips, and whether the
 * skill has earned a promotion up the autonomy ladder.
 *
 * This is the section that decides whether an agent gets more rope, so it is
 * computed, never narrated by a model. "Clean" means the user approved the
 * draft with an edit distance under RevisionRecorder::CLEAN_THRESHOLD — not
 * "nobody complained".
 *
 * A promotion is *proposed with its evidence*, never applied. Moving a skill
 * from human_led to assisted is the user's decision and the letter's job is to
 * hand them the numbers for it.
 */
class TrustLedger
{
    /** Clean drafts in a row before the letter proposes the next rung. */
    public const PROMOTION_GATE = 20;

    public const LADDER = ['human_led' => 'assisted', 'assisted' => 'autonomous'];

    public function __construct(private readonly RevisionRecorder $revisions) {}

    /**
     * @return array{rows: list<array<string, mixed>>, promotions: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function forWeek(string $tenantId, Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('agent_runs')) {
            return ['rows' => [], 'promotions' => [], 'totals' => ['runs' => 0, 'drafts' => 0, 'clean' => 0, 'edited' => 0, 'rejected' => 0]];
        }

        $runs = DB::table('agent_runs')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('skill_slug, count(*) as runs')
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->selectRaw("sum(case when status in ('waiting_input', 'blocked') then 1 else 0 end) as waiting")
            ->groupBy('skill_slug')
            ->get();

        $rows = [];
        $totals = ['runs' => 0, 'drafts' => 0, 'clean' => 0, 'edited' => 0, 'rejected' => 0];

        foreach ($runs as $run) {
            $slug = (string) $run->skill_slug;
            $outcome = $this->draftOutcomes($tenantId, $slug, $from, $to);
            $decided = $outcome['clean'] + $outcome['edited'] + $outcome['rejected'];

            $rows[] = [
                'skill_slug' => $slug,
                'runs' => (int) $run->runs,
                'failed' => (int) $run->failed,
                'waiting' => (int) $run->waiting,
                'drafts' => $outcome['drafts'],
                'clean' => $outcome['clean'],
                'edited' => $outcome['edited'],
                'rejected' => $outcome['rejected'],
                // Percentages are only meaningful once something was actually decided.
                'clean_pct' => $decided > 0 ? round($outcome['clean'] / $decided * 100) : null,
                'edited_pct' => $decided > 0 ? round($outcome['edited'] / $decided * 100) : null,
                'rejected_pct' => $decided > 0 ? round($outcome['rejected'] / $decided * 100) : null,
                'breaker_trips' => $this->breakerTrips($tenantId, $slug, $from, $to),
                'autonomy' => $this->autonomyOf($tenantId, $slug),
                'clean_streak' => $this->cleanStreak($tenantId, $slug),
            ];

            $totals['runs'] += (int) $run->runs;
            foreach (['drafts', 'clean', 'edited', 'rejected'] as $key) {
                $totals[$key] += $outcome[$key];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['runs'], $a['skill_slug']] <=> [$a['runs'], $b['skill_slug']]);

        return ['rows' => $rows, 'promotions' => $this->promotions($rows), 'totals' => $totals];
    }

    /**
     * Drafts this skill produced in the window and what became of them.
     *
     * @return array{drafts: int, clean: int, edited: int, rejected: int}
     */
    private function draftOutcomes(string $tenantId, string $slug, Carbon $from, Carbon $to): array
    {
        $empty = ['drafts' => 0, 'clean' => 0, 'edited' => 0, 'rejected' => 0];

        if (! Schema::hasTable('agent_artifacts')) {
            return $empty;
        }

        // An artifact carries its own skill_slug, but run_id is the authority
        // when it does not (older rows, and anything written by a tool that
        // only knew the run). Match on either.
        $artifacts = DB::table('agent_artifacts')
            ->leftJoin('agent_runs', 'agent_runs.id', '=', 'agent_artifacts.run_id')
            ->where('agent_artifacts.tenant_id', $tenantId)
            ->where(function ($query) use ($slug): void {
                $query->where('agent_artifacts.skill_slug', $slug)->orWhere('agent_runs.skill_slug', $slug);
            })
            ->where('agent_artifacts.created_at', '>=', $from)->where('agent_artifacts.created_at', '<', $to)
            ->get(['agent_artifacts.id', 'agent_artifacts.status']);

        if ($artifacts->isEmpty()) {
            return $empty;
        }

        $rejected = $artifacts->filter(fn ($a): bool => (string) $a->status === 'rejected')->count();
        $accepted = $artifacts->filter(fn ($a): bool => in_array((string) $a->status, ['approved', 'applied'], true));

        // An accepted draft is "clean" unless a revision was recorded against
        // it with a distance over the threshold.
        $edited = 0;
        if ($accepted->isNotEmpty() && Schema::hasTable('artifact_revisions')) {
            $edited = DB::table('artifact_revisions')
                ->where('tenant_id', $tenantId)
                ->where('subject_type', 'agent_artifact')
                ->whereIn('subject_id', $accepted->pluck('id')->map(fn ($id) => (string) $id)->all())
                ->where('action', 'edit')
                ->where('distance', '>=', RevisionRecorder::CLEAN_THRESHOLD)
                ->distinct()
                ->count('subject_id');
        }

        return [
            'drafts' => $artifacts->count(),
            'clean' => max(0, $accepted->count() - $edited),
            'edited' => $edited,
            'rejected' => $rejected,
        ];
    }

    private function breakerTrips(string $tenantId, string $slug, Carbon $from, Carbon $to): int
    {
        if (! Schema::hasTable('tenant_agent_states')) {
            return 0;
        }

        return DB::table('tenant_agent_states')
            ->where('tenant_id', $tenantId)
            ->where('scope_id', $slug)
            ->where('updated_at', '>=', $from)->where('updated_at', '<', $to)
            ->count();
    }

    private function autonomyOf(string $tenantId, string $slug): string
    {
        if (! Schema::hasTable('tenant_skills')) {
            return 'human_led';
        }

        return (string) (DB::table('tenant_skills')
            ->where('tenant_id', $tenantId)->where('skill_slug', $slug)
            ->value('autonomy_level') ?? 'human_led');
    }

    private function cleanStreak(string $tenantId, string $slug): int
    {
        if (! Schema::hasTable('tenant_skills')) {
            return 0;
        }

        return (int) (DB::table('tenant_skills')
            ->where('tenant_id', $tenantId)->where('skill_slug', $slug)
            ->value('clean_drafts_count') ?? 0);
    }

    /**
     * Skills that have cleared the gate, with the evidence for the proposal.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function promotions(array $rows): array
    {
        $proposals = [];

        foreach ($rows as $row) {
            $next = self::LADDER[$row['autonomy']] ?? null;
            if ($next === null || $row['clean_streak'] < self::PROMOTION_GATE || $row['rejected'] > 0) {
                continue;
            }

            $proposals[] = [
                'skill_slug' => $row['skill_slug'],
                'from' => $row['autonomy'],
                'to' => $next,
                'evidence' => sprintf(
                    '%d clean drafts in a row; %d run%s this week, %d rejected, %d breaker trip%s',
                    $row['clean_streak'],
                    $row['runs'],
                    $row['runs'] === 1 ? '' : 's',
                    $row['rejected'],
                    $row['breaker_trips'],
                    $row['breaker_trips'] === 1 ? '' : 's',
                ),
            ];
        }

        return $proposals;
    }

    /** Skills enabled for the tenant that produced nothing at all this week. */
    public function idleSkills(string $tenantId, array $activeSlugs): array
    {
        if (! class_exists(TenantSkill::class) || ! Schema::hasTable('tenant_skills')) {
            return [];
        }

        return DB::table('tenant_skills')
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->whereNotIn('skill_slug', $activeSlugs === [] ? ['__none__'] : $activeSlugs)
            ->pluck('skill_slug')
            ->map(fn ($slug) => (string) $slug)
            ->all();
    }
}
