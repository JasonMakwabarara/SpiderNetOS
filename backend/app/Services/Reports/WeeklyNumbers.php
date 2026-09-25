<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The seven numbers of the Monday letter (plan D8 #11), each with the delta
 * against the same measure a week earlier:
 *
 *   1. cash + runway            2. overdue AR + DSO       3. AP due in 14 days
 *   4. spend vs budget          5. weighted pipeline      6. CAC / LTV (n >= 5)
 *   7. agent cost vs output
 *
 * Two rules the letter depends on:
 *
 *  - A number that cannot be computed comes back `available: false` with a
 *    plain reason. It is never silently rendered as zero — "$0 cash" and "we
 *    have no wallet yet" are different sentences and only one of them is true.
 *  - Every table is checked with Schema::hasTable first. A lean install does
 *    not carry the whole finance schema, and a missing table is a missing
 *    number, not a failed letter.
 */
class WeeklyNumbers
{
    public const KEYS = ['cash_runway', 'overdue_ar', 'ap_due_14d', 'spend_vs_budget', 'weighted_pipeline', 'cac_ltv', 'agent_cost'];

    /** CAC/LTV is noise below this many closed-won deals. */
    public const MIN_DEALS_FOR_UNIT_ECONOMICS = 5;

    /** Stage -> probability for the weighted pipeline. */
    public const STAGE_WEIGHTS = [
        'lead' => 0.05, 'qualified' => 0.15, 'discovery' => 0.25, 'proposal' => 0.40,
        'negotiation' => 0.65, 'verbal' => 0.85, 'won' => 1.0, 'closed_won' => 1.0,
        'lost' => 0.0, 'closed_lost' => 0.0,
    ];

    /** An unrecognised deal stage is still counted, conservatively, never dropped. */
    public const UNKNOWN_STAGE_WEIGHT = 0.25;

    /**
     * @param  Carbon  $weekStart  Monday 00:00 of the week being reported (tenant-local)
     * @return array<string, array<string, mixed>>
     */
    public function forWeek(string $tenantId, Carbon $weekStart): array
    {
        $weekEnd = $weekStart->copy()->addWeek();
        $priorStart = $weekStart->copy()->subWeek();

        return [
            'cash_runway' => $this->cashAndRunway($tenantId, $weekStart),
            'overdue_ar' => $this->overdueAr($tenantId, $weekStart),
            'ap_due_14d' => $this->apDueSoon($tenantId, $weekStart),
            'spend_vs_budget' => $this->spendVsBudget($tenantId, $weekStart),
            'weighted_pipeline' => $this->weightedPipeline($tenantId, $weekStart),
            'cac_ltv' => $this->unitEconomics($tenantId, $weekStart),
            'agent_cost' => $this->agentCostVsOutput($tenantId, $weekStart, $weekEnd, $priorStart),
        ];
    }

    // ------------------------------------------------------------------ //
    //  1. Cash and runway
    // ------------------------------------------------------------------ //

    private function cashAndRunway(string $tenantId, Carbon $at): array
    {
        if (! Schema::hasTable('wallets')) {
            return $this->unavailable('cash_runway', 'Cash + runway', 'no wallet on this install');
        }

        $cash = (float) DB::table('wallets')->where('tenant_id', $tenantId)->where('status', 'active')->sum('balance');

        // Burn: 90 days of completed outbound payments, expressed per 30 days.
        $burn = null;
        if (Schema::hasTable('payments')) {
            $spent = (float) DB::table('payments')
                ->where('tenant_id', $tenantId)
                ->where('status', 'completed')
                ->where('type', 'sent')
                ->where('paid_at', '>=', $at->copy()->subDays(90))
                ->where('paid_at', '<', $at)
                ->sum('amount');
            $burn = $spent > 0 ? $spent / 3 : null;
        }

        $runway = ($burn !== null && $burn > 0) ? $cash / $burn : null;

        return [
            'key' => 'cash_runway',
            'label' => 'Cash + runway',
            'available' => true,
            'value' => $cash,
            'format' => 'money',
            'detail' => $runway === null
                ? 'no outbound payments in 90 days, so there is no burn rate to divide by'
                : sprintf('%.1f months at the last 90 days of burn (%s a month)', $runway, self::money((float) $burn)),
            'extra' => ['runway_months' => $runway === null ? null : round($runway, 1), 'burn_per_month' => $burn],
        ];
    }

    // ------------------------------------------------------------------ //
    //  2. Overdue AR + DSO
    // ------------------------------------------------------------------ //

    private function overdueAr(string $tenantId, Carbon $at): array
    {
        if (! Schema::hasTable('invoices')) {
            return $this->unavailable('overdue_ar', 'Overdue AR', 'no invoices on this install');
        }

        $open = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['paid', 'cancelled', 'draft'])
            ->get(['total_amount', 'due_date', 'issue_date']);

        if ($open->isEmpty()) {
            return [
                'key' => 'overdue_ar', 'label' => 'Overdue AR', 'available' => true, 'value' => 0.0,
                'format' => 'money', 'detail' => 'nothing outstanding', 'extra' => ['dso' => null, 'count' => 0, 'outstanding' => 0.0],
            ];
        }

        $overdue = 0.0;
        $overdueCount = 0;
        $weightedDays = 0.0;
        $outstanding = 0.0;

        foreach ($open as $invoice) {
            $amount = (float) $invoice->total_amount;
            $outstanding += $amount;

            if ($invoice->due_date !== null && Carbon::parse($invoice->due_date)->lt($at)) {
                $overdue += $amount;
                $overdueCount++;
            }
            if ($invoice->issue_date !== null) {
                $weightedDays += $amount * max(0, Carbon::parse($invoice->issue_date)->diffInDays($at, false));
            }
        }

        $dso = $outstanding > 0 ? $weightedDays / $outstanding : null;

        return [
            'key' => 'overdue_ar',
            'label' => 'Overdue AR',
            'available' => true,
            'value' => $overdue,
            'format' => 'money',
            'detail' => $overdueCount === 0
                ? sprintf('%s outstanding, none of it late', self::money($outstanding))
                : sprintf('%d invoice%s past due out of %s outstanding', $overdueCount, $overdueCount === 1 ? '' : 's', self::money($outstanding)),
            'extra' => ['dso' => $dso === null ? null : round($dso, 1), 'count' => $overdueCount, 'outstanding' => $outstanding],
        ];
    }

    // ------------------------------------------------------------------ //
    //  3. AP due in 14 days
    // ------------------------------------------------------------------ //

    private function apDueSoon(string $tenantId, Carbon $at): array
    {
        if (! Schema::hasTable('bills')) {
            return $this->unavailable('ap_due_14d', 'AP due in 14 days', 'no bills on this install');
        }

        $rows = DB::table('bills')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['paid', 'void', 'draft'])
            ->whereBetween('due_date', [$at->copy()->subYear()->toDateString(), $at->copy()->addDays(14)->toDateString()])
            ->get(['total_amount', 'due_date']);

        $due = 0.0;
        $late = 0.0;
        foreach ($rows as $bill) {
            $amount = (float) $bill->total_amount;
            $due += $amount;
            if ($bill->due_date !== null && Carbon::parse($bill->due_date)->lt($at)) {
                $late += $amount;
            }
        }

        return [
            'key' => 'ap_due_14d',
            'label' => 'AP due in 14 days',
            'available' => true,
            'value' => $due,
            'format' => 'money',
            'detail' => $late > 0
                ? sprintf('%s of that is already late', self::money($late))
                : ($rows->isEmpty() ? 'nothing due' : sprintf('%d bill%s, none late', $rows->count(), $rows->count() === 1 ? '' : 's')),
            'extra' => ['already_late' => $late, 'count' => $rows->count()],
        ];
    }

    // ------------------------------------------------------------------ //
    //  4. Spend vs budget
    // ------------------------------------------------------------------ //

    private function spendVsBudget(string $tenantId, Carbon $at): array
    {
        if (! Schema::hasTable('budgets')) {
            return $this->unavailable('spend_vs_budget', 'Spend vs budget', 'no budgets on this install');
        }

        $budgets = DB::table('budgets')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('period_start', '<=', $at->toDateString())
            ->where('period_end', '>=', $at->toDateString())
            ->get(['name', 'amount', 'spent']);

        if ($budgets->isEmpty()) {
            return $this->unavailable('spend_vs_budget', 'Spend vs budget', 'no budget covers this week');
        }

        $budgeted = 0.0;
        $spent = 0.0;
        $over = [];
        foreach ($budgets as $budget) {
            $amount = (float) $budget->amount;
            $used = (float) $budget->spent;
            $budgeted += $amount;
            $spent += $used;
            if ($amount > 0 && $used > $amount) {
                $over[] = (string) $budget->name;
            }
        }

        $pct = $budgeted > 0 ? $spent / $budgeted * 100 : null;

        return [
            'key' => 'spend_vs_budget',
            'label' => 'Spend vs budget',
            'available' => true,
            'value' => $spent,
            'format' => 'money',
            'detail' => $over !== []
                ? 'over budget on '.implode(', ', array_slice($over, 0, 3))
                : ($pct === null ? 'no budget amount set' : sprintf('%.0f%% of %s for the period', $pct, self::money($budgeted))),
            'extra' => ['budgeted' => $budgeted, 'pct_used' => $pct === null ? null : round($pct, 1), 'over' => $over],
        ];
    }

    // ------------------------------------------------------------------ //
    //  5. Weighted pipeline
    // ------------------------------------------------------------------ //

    private function weightedPipeline(string $tenantId, Carbon $at): array
    {
        if (! Schema::hasTable('deals')) {
            return $this->unavailable('weighted_pipeline', 'Weighted pipeline', 'no deals on this install');
        }

        $deals = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->get(['value_cents', 'stage', 'expected_close_at']);

        $weighted = 0.0;
        $unweighted = 0.0;
        $pastExpected = 0;
        $unknownStages = [];

        foreach ($deals as $deal) {
            $value = ((int) $deal->value_cents) / 100;
            $unweighted += $value;

            $stage = (string) $deal->stage;
            if (! array_key_exists($stage, self::STAGE_WEIGHTS)) {
                $unknownStages[$stage] = true;
            }
            $weighted += $value * (self::STAGE_WEIGHTS[$stage] ?? self::UNKNOWN_STAGE_WEIGHT);

            if ($deal->expected_close_at !== null && Carbon::parse($deal->expected_close_at)->lt($at)) {
                $pastExpected++;
            }
        }

        return [
            'key' => 'weighted_pipeline',
            'label' => 'Weighted pipeline',
            'available' => true,
            'value' => $weighted,
            'format' => 'money',
            'detail' => $deals->isEmpty()
                ? 'no open deals'
                : sprintf(
                    '%d open deal%s worth %s unweighted%s',
                    $deals->count(),
                    $deals->count() === 1 ? '' : 's',
                    self::money($unweighted),
                    $pastExpected > 0 ? sprintf('; %d past its expected close date', $pastExpected) : '',
                ),
            'extra' => [
                'unweighted' => $unweighted,
                'open_deals' => $deals->count(),
                'past_expected_close' => $pastExpected,
                'unknown_stages' => array_keys($unknownStages),
            ],
        ];
    }

    // ------------------------------------------------------------------ //
    //  6. CAC / LTV, held back until the sample is worth reporting
    // ------------------------------------------------------------------ //

    private function unitEconomics(string $tenantId, Carbon $at): array
    {
        if (! Schema::hasTable('deals')) {
            return $this->unavailable('cac_ltv', 'CAC / LTV', 'no deals on this install');
        }

        $won = DB::table('deals')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('closed_at')
            ->whereIn('stage', ['won', 'closed_won'])
            ->where('closed_at', '>=', $at->copy()->subDays(365))
            ->get(['value_cents']);

        if ($won->count() < self::MIN_DEALS_FOR_UNIT_ECONOMICS) {
            return $this->unavailable('cac_ltv', 'CAC / LTV', sprintf(
                '%d closed-won deal%s in the last year — held back until there are %d, because the average of four numbers is not a unit economic',
                $won->count(),
                $won->count() === 1 ? '' : 's',
                self::MIN_DEALS_FOR_UNIT_ECONOMICS,
            ));
        }

        $revenue = 0.0;
        foreach ($won as $deal) {
            $revenue += ((int) $deal->value_cents) / 100;
        }
        $ltv = $revenue / $won->count();

        $cac = null;
        if (Schema::hasTable('expense_items')) {
            $spend = (float) DB::table('expense_items')
                ->where('tenant_id', $tenantId)
                ->where('expense_date', '>=', $at->copy()->subDays(365)->toDateString())
                ->sum('amount');
            $cac = $spend > 0 ? $spend / $won->count() : null;
        }

        return [
            'key' => 'cac_ltv',
            'label' => 'CAC / LTV',
            'available' => true,
            'value' => $ltv,
            'format' => 'money',
            'detail' => $cac === null
                ? sprintf('average value of %d wins; no recorded spend to divide for CAC', $won->count())
                : sprintf('CAC %s against LTV %s — a %.1fx ratio', self::money($cac), self::money($ltv), $cac > 0 ? $ltv / $cac : 0.0),
            'extra' => ['ltv' => $ltv, 'cac' => $cac, 'deals_won' => $won->count()],
        ];
    }

    // ------------------------------------------------------------------ //
    //  7. Agent cost vs output — the only number with a real week-on-week delta
    // ------------------------------------------------------------------ //

    private function agentCostVsOutput(string $tenantId, Carbon $from, Carbon $to, Carbon $priorFrom): array
    {
        if (! Schema::hasTable('agent_runs')) {
            return $this->unavailable('agent_cost', 'Agent cost vs output', 'the agent runtime has not run yet');
        }

        $cost = fn (Carbon $a, Carbon $b): float => (float) DB::table('agent_runs')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $a)
            ->where('created_at', '<', $b)
            ->sum('cost_usd');

        $thisWeek = $cost($from, $to);
        $lastWeek = $cost($priorFrom, $from);

        $runs = DB::table('agent_runs')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->count();

        $artifacts = Schema::hasTable('agent_artifacts')
            ? DB::table('agent_artifacts')
                ->where('tenant_id', $tenantId)
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)
                ->count()
            : 0;

        return [
            'key' => 'agent_cost',
            'label' => 'Agent cost vs output',
            'available' => true,
            'value' => $thisWeek,
            'format' => 'money_precise',
            'delta' => self::delta($thisWeek, $lastWeek),
            'detail' => $runs === 0
                ? 'no runs this week'
                : sprintf(
                    '%d run%s produced %d draft%s — %s',
                    $runs,
                    $runs === 1 ? '' : 's',
                    $artifacts,
                    $artifacts === 1 ? '' : 's',
                    $artifacts > 0 ? self::money($thisWeek / $artifacts, 3).' a draft' : 'nothing to divide by yet',
                ),
            'extra' => ['runs' => $runs, 'artifacts' => $artifacts, 'last_week' => $lastWeek],
        ];
    }

    // ------------------------------------------------------------------ //
    //  Helpers
    // ------------------------------------------------------------------ //

    /** @return array<string, mixed> */
    private function unavailable(string $key, string $label, string $why): array
    {
        return [
            'key' => $key, 'label' => $label, 'available' => false, 'value' => null,
            'format' => 'money', 'detail' => $why, 'extra' => [],
        ];
    }

    /**
     * Week-on-week movement. Null in, null out — a delta against a number we
     * never had is not zero.
     *
     * @return array{absolute: float, pct: float|null, direction: string}|null
     */
    public static function delta(?float $now, ?float $before): ?array
    {
        if ($now === null || $before === null) {
            return null;
        }

        $absolute = $now - $before;

        return [
            'absolute' => $absolute,
            'pct' => abs($before) > 0.00001 ? round($absolute / abs($before) * 100, 1) : null,
            'direction' => abs($absolute) < 0.005 ? 'flat' : ($absolute > 0 ? 'up' : 'down'),
        ];
    }

    public static function money(float $amount, int $decimals = 2): string
    {
        return '$'.number_format($amount, $decimals);
    }
}
