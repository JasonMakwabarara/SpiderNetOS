<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Services\Agents\Exceptions\BudgetExceededException;
use App\Services\CostGovernor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Three budgets, checked before every model or tool call (plan D3):
 *   1. per-run cap      — card cost_budget.per_run_usd (config default)
 *   2. per-agent daily  — Redis counter cost:agent:{id}:{Y-m-d}, DB fallback
 *                         SUM(agent_runs.cost_usd) today; cap from
 *                         tenant_skills.budget_daily_usd, else the workspace
 *   3. tenant           — CostGovernor::canExecute (daily/monthly ceilings)
 */
final class RunBudget
{
    private const AGENT_KEY = 'cost:agent:%s:%s';

    public function __construct(private readonly CostGovernor $costs) {}

    /** @throws BudgetExceededException */
    public function assert(RunContext $ctx, float $estimate = 0.0): void
    {
        $estimate = max(0.0, $estimate);

        $perRun = $ctx->card->perRunBudgetUsd() ?? (float) config('agents.per_run_budget_usd', 0.25);
        if ($perRun > 0 && $ctx->costSoFar() + $estimate > $perRun) {
            throw new BudgetExceededException('per_run_budget_exceeded', [
                'cap_usd' => $perRun, 'spent_usd' => round($ctx->costSoFar(), 6), 'estimate_usd' => $estimate,
            ]);
        }

        $agentId = $ctx->agentId();
        if ($agentId !== null) {
            $dailyCap = $this->dailyCap($ctx);
            if ($dailyCap > 0) {
                $spentToday = self::agentDailySpend($agentId);
                if ($spentToday + $estimate > $dailyCap) {
                    throw new BudgetExceededException('agent_daily_budget_exceeded', [
                        'cap_usd' => $dailyCap, 'spent_usd' => round($spentToday, 6), 'estimate_usd' => $estimate, 'agent_id' => $agentId,
                    ]);
                }
            }
        }

        $status = $this->costs->canExecute($ctx->tenantId, $estimate);
        if (! ($status['allowed'] ?? false)) {
            throw new BudgetExceededException('tenant_budget_exceeded', [
                'daily_remaining' => $status['daily_remaining'] ?? null,
                'monthly_remaining' => $status['monthly_remaining'] ?? null,
                'estimate_usd' => $estimate,
            ]);
        }
    }

    /** Charge a cost against the in-flight run, the agent's daily counter and the workspace. */
    public function record(RunContext $ctx, float $cost, int $tokens = 0): void
    {
        $ctx->addSpend($cost, $tokens);
        if ($cost <= 0) {
            return;
        }

        $agentId = $ctx->agentId();
        if ($agentId !== null) {
            try {
                $key = self::dailyKey($agentId);
                Redis::incrbyfloat($key, $cost);
                Redis::expire($key, 86400 * 3);
            } catch (\Throwable $e) {
                Log::debug('agent daily cost counter skipped (Redis unavailable)', ['agent_id' => $agentId, 'error' => $e->getMessage()]);
            }
        }

        if ($ctx->workspace !== null) {
            self::chargeWorkspace($ctx->workspace, $cost);
        }
    }

    public static function chargeWorkspace(AgentWorkspace $workspace, float $cost): void
    {
        $today = now()->toDateString();
        $sameDay = $workspace->spent_day !== null && $workspace->spent_day->toDateString() === $today;
        $spent = ($sameDay ? (float) $workspace->spent_today_usd : 0.0) + $cost;

        AgentWorkspace::whereKey($workspace->id)->update([
            'spent_today_usd' => round($spent, 4),
            'spent_day' => $today,
            'updated_at' => now(),
        ]);
        $workspace->spent_today_usd = $spent;
        $workspace->spent_day = now();
    }

    public static function dailyKey(string $agentId, ?string $date = null): string
    {
        return sprintf(self::AGENT_KEY, $agentId, $date ?? now()->toDateString());
    }

    /** Redis counter when present, else the DB projection of today's runs. */
    public static function agentDailySpend(string $agentId): float
    {
        try {
            $value = Redis::get(self::dailyKey($agentId));
            if ($value !== null && $value !== false && $value !== '') {
                return (float) $value;
            }
        } catch (\Throwable) {
            // fall through to the DB projection
        }

        try {
            return (float) AgentRun::query()
                ->where('agent_id', $agentId)
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('cost_usd');
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function dailyCap(RunContext $ctx): float
    {
        $skillCap = $ctx->tenantSkill?->budget_daily_usd;
        if ($skillCap !== null && (float) $skillCap > 0) {
            return (float) $skillCap;
        }
        if ($ctx->workspace !== null && (float) $ctx->workspace->budget_daily_usd > 0) {
            return (float) $ctx->workspace->budget_daily_usd;
        }

        return $ctx->card->dailyBudgetUsd() ?? (float) config('agents.default_daily_budget_usd', 2.0);
    }
}
