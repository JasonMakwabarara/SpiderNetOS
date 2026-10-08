<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Tenant;
use App\Services\Founder\BrainWriter;
use App\Services\Founder\FounderBriefService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The Monday letter (plan D8 #11) — one delivered weekly digest that replaces
 * three separate ones:
 *
 *   the seven numbers with deltas · a trust section per skill · locking
 *   antlers · one experiment · reply-to-act · and, as its second half, the
 *   C-Suite newsletter
 *
 * Filed at reports/weekly/YYYY-WW.md in the Knowledge brain, with the C-Suite
 * half also filed at reports/weekly/YYYY-WW-csuite.md so it can be read (and
 * spoken) on its own.
 *
 * Deterministic end to end: no model writes a number, a trust percentage or a
 * promotion proposal. A letter the user cannot trust arithmetically is worse
 * than no letter, because they would stop checking.
 */
class MondayLetterComposer
{
    public function __construct(
        private readonly WeeklyNumbers $numbers,
        private readonly TrustLedger $trust,
        private readonly CSuiteNewsletterComposer $csuite,
        private readonly BrainWriter $writer,
    ) {}

    /**
     * @param  Carbon|null  $for  any moment in the week to report; defaults to the week just ended
     * @return array{tenant_id: string, period: string, week_start: string, week_end: string, timezone: string, numbers: array<string, mixed>, trust: array<string, mixed>, antlers: array<string, mixed>|null, experiment: array<string, mixed>|null, csuite: array<string, mixed>, markdown: string, paths: array<string, string|null>}
     */
    public function compose(string $tenantId, ?Carbon $for = null): array
    {
        $tenant = Tenant::find($tenantId);
        $zone = self::timezoneOf($tenant);

        // The Monday letter reports the week that just ended.
        $now = ($for ? $for->copy() : now())->setTimezone($zone);
        $weekStart = $now->copy()->startOfWeek();
        if ($for === null) {
            $weekStart = $weekStart->subWeek();
        }
        $weekEnd = $weekStart->copy()->addWeek();

        $numbers = $this->numbers->forWeek($tenantId, $weekStart);
        $trust = $this->trust->forWeek($tenantId, $weekStart, $weekEnd);

        $letter = [
            'tenant_id' => $tenantId,
            'period' => $weekStart->format('o-\WW'),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->copy()->subDay()->toDateString(),
            'timezone' => $zone,
            'numbers' => $numbers,
            'trust' => $trust,
            'antlers' => $this->antlers($tenantId, $numbers, $trust),
            'experiment' => $this->experiment($numbers, $trust),
        ];

        $letter['csuite'] = $this->csuite->compose($tenantId, $weekStart, ['numbers' => $numbers, 'trust' => $trust]);
        $letter['markdown'] = $this->markdown($letter);
        $letter['paths'] = $this->file($tenantId, $letter);

        $this->csuite->record($tenantId, $letter['csuite'], $letter['paths']['csuite'] ?? null);

        return $letter;
    }

    // ------------------------------------------------------------------ //
    //  Locking antlers — one place the data disagrees with the plan
    // ------------------------------------------------------------------ //

    /**
     * Priestley's "locking antlers": the letter's job is to say the awkward
     * thing once, with the evidence, and then stop. Exactly one point, the
     * strongest available, or none at all — a letter that argues every week
     * stops being read.
     *
     * @return array<string, mixed>|null
     */
    private function antlers(string $tenantId, array $numbers, array $trust): ?array
    {
        $candidates = [];

        // 1. A skill you keep running and keep rejecting.
        foreach ($trust['rows'] as $row) {
            if ($row['rejected'] >= 3 && ($row['rejected_pct'] ?? 0) >= 50) {
                $candidates[] = [
                    'weight' => 100 + $row['rejected'],
                    'title' => 'You are rejecting more of '.$row['skill_slug'].' than you are keeping',
                    'detail' => sprintf(
                        '%d of the %d drafts you decided on were rejected. Either the brief is wrong or the brain is missing what it needs — it is cheaper to fix one of those than to keep reading drafts you will not send.',
                        $row['rejected'],
                        $row['clean'] + $row['edited'] + $row['rejected'],
                    ),
                    'action' => ['label' => 'Open the skill', 'path' => '/skills/'.$row['skill_slug']],
                ];
            }
        }

        // 2. Spend is over budget while the pipeline has not moved.
        $spend = $numbers['spend_vs_budget'] ?? null;
        $pipeline = $numbers['weighted_pipeline'] ?? null;
        if (($spend['available'] ?? false) && ($spend['extra']['over'] ?? []) !== [] && ($pipeline['available'] ?? false) && (float) ($pipeline['value'] ?? 0) <= 0.0) {
            $candidates[] = [
                'weight' => 90,
                'title' => 'Spending is over budget with nothing weighted in the pipeline',
                'detail' => sprintf('Over on %s, and the weighted pipeline is empty. Money is going out against work that has not been sold yet.', implode(', ', $spend['extra']['over'])),
                'action' => ['label' => 'Open spend', 'path' => '/operate/spend'],
            ];
        }

        // 3. Cash is being collected slower than it is being spent.
        $ar = $numbers['overdue_ar'] ?? null;
        $ap = $numbers['ap_due_14d'] ?? null;
        if (($ar['available'] ?? false) && ($ap['available'] ?? false)
            && (float) $ar['value'] > 0 && (float) $ar['value'] > (float) $ap['value']) {
            $candidates[] = [
                'weight' => 80,
                'title' => 'More is owed to you than you owe, and it is late',
                'detail' => sprintf(
                    '%s of your invoices are past due while %s of bills fall due in 14 days. Chasing the first funds the second.',
                    WeeklyNumbers::money((float) $ar['value']),
                    WeeklyNumbers::money((float) $ap['value']),
                ),
                'action' => ['label' => 'Open receivables', 'path' => '/financial/invoices'],
            ];
        }

        // 4. Skills switched on that never run.
        $idle = $this->trust->idleSkills($tenantId, array_column($trust['rows'], 'skill_slug'));
        if (count($idle) >= 3) {
            $candidates[] = [
                'weight' => 50,
                'title' => count($idle).' enabled skills did nothing this week',
                'detail' => 'Enabled and idle: '.implode(', ', array_slice($idle, 0, 5)).'. Either they have no trigger or they are waiting on something. An agent that never runs is not a capability.',
                'action' => ['label' => 'Open the roster', 'path' => '/skills'],
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return $candidates[0];
    }

    // ------------------------------------------------------------------ //
    //  One experiment
    // ------------------------------------------------------------------ //

    /**
     * One thing to try this week, derived from what the week actually showed.
     * Proposed, never started — the letter does not enrol the business in an
     * experiment it did not agree to.
     *
     * @return array<string, mixed>|null
     */
    private function experiment(array $numbers, array $trust): ?array
    {
        foreach ($trust['rows'] as $row) {
            if (($row['edited_pct'] ?? 0) >= 50 && $row['edited'] >= 3) {
                return [
                    'title' => 'Fold your edits to '.$row['skill_slug'].' back into its brief',
                    'hypothesis' => 'If the corrections you keep making are written into the skill, the next batch needs fewer of them.',
                    'measure' => 'Clean-draft share for '.$row['skill_slug'].', this week against next.',
                    'source' => sprintf('%d of %d kept drafts needed an edit', $row['edited'], $row['clean'] + $row['edited']),
                ];
            }
        }

        foreach ($trust['promotions'] as $promotion) {
            return [
                'title' => 'Run '.$promotion['skill_slug'].' at '.$promotion['to'].' for one week',
                'hypothesis' => 'It has earned the next rung; a week at '.$promotion['to'].' shows whether that holds without you reading every draft.',
                'measure' => 'Rejections and breaker trips over the week — any of either, and it goes back.',
                'source' => $promotion['evidence'],
            ];
        }

        $agentCost = $numbers['agent_cost'] ?? null;
        if (($agentCost['available'] ?? false) && ($agentCost['extra']['runs'] ?? 0) === 0) {
            return [
                'title' => 'Turn one skill on and let it run for a week',
                'hypothesis' => 'Nothing ran this week, so there is nothing to learn from. One live skill produces more signal than a month of planning.',
                'measure' => 'Drafts produced, and how many you keep.',
                'source' => 'no agent runs in the reported week',
            ];
        }

        return null;
    }

    // ------------------------------------------------------------------ //
    //  Render
    // ------------------------------------------------------------------ //

    public function markdown(array $letter): string
    {
        $lines = [
            '# Monday letter — week of '.$letter['week_start'],
            '',
            '_'.$letter['week_start'].' to '.$letter['week_end'].', '.$letter['timezone'].'._',
            '',
            '## The numbers',
            '',
        ];

        foreach (WeeklyNumbers::KEYS as $key) {
            $number = $letter['numbers'][$key] ?? null;
            if ($number === null) {
                continue;
            }
            $lines[] = '- '.$this->numberLine($number);
        }

        $lines[] = '';
        $lines[] = '## Trust';
        $lines[] = '';

        if ($letter['trust']['rows'] === []) {
            $lines[] = 'No skill ran this week.';
        } else {
            $lines[] = '| Skill | Runs | Drafts | Clean | Edited | Rejected | Autonomy |';
            $lines[] = '| --- | ---: | ---: | ---: | ---: | ---: | --- |';
            foreach ($letter['trust']['rows'] as $row) {
                $lines[] = sprintf(
                    '| %s | %d | %d | %s | %s | %s | %s |',
                    $row['skill_slug'],
                    $row['runs'],
                    $row['drafts'],
                    $this->pct($row['clean'], $row['clean_pct']),
                    $this->pct($row['edited'], $row['edited_pct']),
                    $this->pct($row['rejected'], $row['rejected_pct']),
                    $row['autonomy'],
                );
            }
        }

        if ($letter['trust']['promotions'] !== []) {
            $lines[] = '';
            $lines[] = '**Promotions proposed** (yours to approve, never automatic):';
            $lines[] = '';
            foreach ($letter['trust']['promotions'] as $promotion) {
                $lines[] = sprintf('- `%s` %s → %s — %s', $promotion['skill_slug'], $promotion['from'], $promotion['to'], $promotion['evidence']);
            }
        }

        if ($letter['antlers'] !== null) {
            $lines[] = '';
            $lines[] = '## Locking antlers';
            $lines[] = '';
            $lines[] = '**'.$letter['antlers']['title'].'**';
            $lines[] = '';
            $lines[] = $letter['antlers']['detail'];
            $lines[] = '';
            $lines[] = sprintf('→ [%s](%s)', $letter['antlers']['action']['label'], $letter['antlers']['action']['path']);
        }

        if ($letter['experiment'] !== null) {
            $lines[] = '';
            $lines[] = '## One experiment';
            $lines[] = '';
            $lines[] = '**'.$letter['experiment']['title'].'**';
            $lines[] = '';
            $lines[] = $letter['experiment']['hypothesis'];
            $lines[] = '';
            $lines[] = '- Measure: '.$letter['experiment']['measure'];
            $lines[] = '- Because: '.$letter['experiment']['source'];
        }

        $lines[] = '';
        $lines[] = rtrim($letter['csuite']['markdown']);
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'Reply to this letter and Atlas acts on it: name a number to see the working, a skill to change its';
        $lines[] = 'brief, or say "promote" to accept a proposal above.';

        return implode("\n", $lines)."\n";
    }

    private function numberLine(array $number): string
    {
        if (! ($number['available'] ?? false)) {
            return sprintf('**%s** — not available: %s', $number['label'], $number['detail']);
        }

        $value = ($number['format'] ?? 'money') === 'money_precise'
            ? WeeklyNumbers::money((float) $number['value'], 4)
            : WeeklyNumbers::money((float) $number['value']);

        $delta = '';
        if (($number['delta'] ?? null) !== null) {
            $direction = $number['delta']['direction'];
            $arrow = $direction === 'up' ? '▲' : ($direction === 'down' ? '▼' : '=');
            $delta = $number['delta']['pct'] === null
                ? sprintf(' (%s %s)', $arrow, WeeklyNumbers::money(abs((float) $number['delta']['absolute']), 4))
                : sprintf(' (%s %s%%)', $arrow, number_format(abs((float) $number['delta']['pct']), 1));
        }

        return sprintf('**%s** — %s%s · %s', $number['label'], $value, $delta, $number['detail']);
    }

    private function pct(int $count, ?float $pct): string
    {
        return $pct === null ? (string) $count : sprintf('%d (%d%%)', $count, (int) round($pct));
    }

    // ------------------------------------------------------------------ //
    //  Filing
    // ------------------------------------------------------------------ //

    /** @return array{letter: string|null, csuite: string|null} */
    private function file(string $tenantId, array $letter): array
    {
        $paths = ['letter' => null, 'csuite' => null];

        $write = function (string $path, string $content, string $title) use ($tenantId): ?string {
            try {
                $version = $this->writer->write($tenantId, $path, $content, [
                    'title' => $title,
                    'source' => 'agent',
                    'author_type' => 'system',
                    'author_ref' => 'MondayLetterComposer',
                    'change_summary' => 'Weekly letter',
                    'data_class' => 'confidential',
                ]);

                return $version === null ? null : $path;
            } catch (\Throwable $e) {
                Log::warning('reports.weekly.write_failed', ['tenant_id' => $tenantId, 'path' => $path, 'error' => $e->getMessage()]);

                return null;
            }
        };

        $paths['letter'] = $write(
            'reports/weekly/'.$letter['period'].'.md',
            $letter['markdown'],
            'Monday letter — '.$letter['period'],
        );
        $paths['csuite'] = $write(
            'reports/weekly/'.$letter['period'].'-csuite.md',
            $letter['csuite']['markdown'],
            'C-Suite letter — '.$letter['period'],
        );

        return $paths;
    }

    /**
     * The same tenant clock the daily brief uses — one definition of "the
     * tenant's Monday", or the two reports would disagree about which week
     * they are describing.
     */
    public static function timezoneOf(?Tenant $tenant): string
    {
        return FounderBriefService::timezoneOf($tenant);
    }

    /** Has this tenant already had the letter for this week? */
    public static function alreadySent(string $tenantId, string $period): bool
    {
        if (! Schema::hasTable('newsletter_issues')) {
            return false;
        }

        return DB::table('newsletter_issues')
            ->where('tenant_id', $tenantId)
            ->where('kind', 'csuite')
            ->where('period', $period)
            ->whereNotNull('sent_at')
            ->exists();
    }
}
