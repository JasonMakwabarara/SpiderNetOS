<?php

namespace App\Http\Controllers;

use App\Services\CostGovernor;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use App\Services\ReplayDivergenceService;
use App\Services\UsageAggregateShadow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * ObservabilityController
 *
 * Handles trace replay, cost budgets, and usage analytics for the
 * SpiderNet OS v3.2 observability dashboard.  All endpoints are
 * tenant-scoped via the ResolveTenant middleware.
 */
class ObservabilityController extends Controller
{
    public function __construct(
        private readonly EventStore             $eventStore,
        private readonly CostGovernor           $costGovernor,
        private readonly ReplayDivergenceService $replayDivergence,
        private readonly UsageAggregateShadow   $shadow,
    ) {}

    // -----------------------------------------------------------------------
    //  Traces
    // -----------------------------------------------------------------------

    /**
     * GET /traces
     * List recent execution traces for the authenticated tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $limit    = (int) $request->query('limit', 50);
        $limit    = min($limit, 200);

        $executions = DB::table('flow_executions')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get([
                'id',
                'flow_id',
                'status',
                'started_at',
                'completed_at',
                'errors',
            ]);

        // Attach duration_ms for convenience
        $traces = $executions->map(function ($exec) {
            $exec->errors = json_decode($exec->errors, true);
            $exec->duration_ms = null;
            if ($exec->started_at && $exec->completed_at) {
                $start = \Carbon\Carbon::parse($exec->started_at);
                $end   = \Carbon\Carbon::parse($exec->completed_at);
                $exec->duration_ms = $start->diffInMilliseconds($end);
            }
            return $exec;
        });

        return response()->json([
            'traces' => $traces,
            'count'  => $traces->count(),
        ]);
    }

    /**
     * GET /traces/{dag_id}
     * Get a single execution trace with its DAG node states.
     */
    public function trace(Request $request, string $dagId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $execution = DB::table('flow_executions')
            ->where('id', $dagId)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$execution) {
            return response()->json(['message' => 'Trace not found.'], 404);
        }

        // Fetch the DAG nodes that belong to this execution's flow
        $nodes = DB::table('dag_nodes')
            ->where('flow_id', $execution->flow_id)
            ->get();

        $edges = DB::table('dag_edges')
            ->where('flow_id', $execution->flow_id)
            ->get();

        // Pull related events from the event store for richer node-level state
        $events = DB::table('event_log')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'flow_execution')
            ->where('aggregate_id', $dagId)
            ->orderBy('sequence_num')
            ->get();

        // Build a node-state map from events
        $nodeStates = [];
        foreach ($events as $event) {
            $payload = json_decode($event->payload, true);
            if (isset($payload['node_id'])) {
                $nodeStates[$payload['node_id']] = [
                    'status'      => $payload['status'] ?? 'unknown',
                    'output'      => $payload['output'] ?? null,
                    'error'       => $payload['error'] ?? null,
                    'occurred_at' => $event->occurred_at,
                ];
            }
        }

        return response()->json([
            'trace' => [
                'execution' => $execution,
                'nodes'     => $nodes,
                'edges'     => $edges,
                'node_states' => $nodeStates,
                'event_count' => $events->count(),
            ],
        ]);
    }

    /**
     * GET /traces/{dag_id}/replay
     * Get the full event log for step-through replay in the UI.
     */
    public function replay(Request $request, string $dagId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        // Verify execution belongs to tenant
        $exists = DB::table('flow_executions')
            ->where('id', $dagId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Trace not found.'], 404);
        }

        $events = DB::table('event_log')
            ->where('tenant_id', $tenantId)
            ->where('aggregate_type', 'flow_execution')
            ->where('aggregate_id', $dagId)
            ->orderBy('sequence_num')
            ->get()
            ->map(function ($event) {
                $event->payload  = json_decode($event->payload, true);
                $event->metadata = json_decode($event->metadata, true);
                return $event;
            });

        $divergenceReport = $this->replayDivergence->detectDivergence($tenantId, $dagId);

        return response()->json([
            'replay' => [
                'dag_id'      => $dagId,
                'steps'       => $events->values(),
                'total_steps' => $events->count(),
                'divergence'  => $divergenceReport,
            ],
        ]);
    }

    /**
     * GET /traces/{dag_id}/divergence
     * On-demand divergence detection report.
     */
    public function divergence(Request $request, string $dagId): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $exists = DB::table('flow_executions')
            ->where('id', $dagId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (!$exists) {
            return response()->json(['message' => 'Trace not found.'], 404);
        }

        $report = $this->replayDivergence->detectDivergence($tenantId, $dagId);
        return response()->json(['divergence' => $report]);
    }

    // -----------------------------------------------------------------------
    //  Budget
    // -----------------------------------------------------------------------

    /**
     * GET /usage/budget
     * Get cost budget configuration for the tenant.
     */
    public function budget(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $budget = DB::table('cost_budgets')
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$budget) {
            // Return system defaults
            return response()->json([
                'budget' => [
                    'daily_limit'     => (float) env('COST_CEILING_DEFAULT', 10.00),
                    'monthly_limit'   => (float) env('COST_CEILING_DEFAULT', 10.00) * 10,
                    'alert_threshold' => 0.80,
                    'action_at_limit' => 'block',
                    'is_default'      => true,
                ],
            ]);
        }

        return response()->json([
            'budget' => [
                'daily_limit'     => (float) $budget->daily_limit,
                'monthly_limit'   => (float) $budget->monthly_limit,
                'alert_threshold' => (float) $budget->alert_threshold,
                'action_at_limit' => $budget->action_at_limit,
                'is_default'      => false,
                'updated_at'      => $budget->updated_at,
            ],
        ]);
    }

    /**
     * PUT /usage/budget
     * Update budget settings for the tenant.
     */
    public function updateBudget(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');

        $validated = $request->validate([
            'daily_limit'     => 'required|numeric|min:0.01',
            'monthly_limit'   => 'required|numeric|min:0.01',
            'alert_threshold' => 'sometimes|numeric|min:0|max:1',
            'action_at_limit' => 'sometimes|in:block,degrade',
        ]);

        DB::table('cost_budgets')->updateOrInsert(
            ['tenant_id' => $tenantId],
            [
                'daily_limit'     => $validated['daily_limit'],
                'monthly_limit'   => $validated['monthly_limit'],
                'alert_threshold' => $validated['alert_threshold'] ?? 0.80,
                'action_at_limit' => $validated['action_at_limit'] ?? 'block',
                'updated_at'      => now(),
            ],
        );

        // Emit event for audit trail
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'budget',
            aggregateId: $tenantId,
            eventType: 'budget.updated',
            payload: $validated,
            metadata: ['user_id' => $request->user()?->id],
        );

        return response()->json([
            'message' => 'Budget updated.',
            'budget'  => $validated,
        ]);
    }

    // -----------------------------------------------------------------------
    //  Usage
    // -----------------------------------------------------------------------

    /**
     * GET /usage/current
     * Get current daily + monthly spend from Redis counters (real-time).
     */
    public function currentUsage(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $today    = now()->toDateString();
        $month    = now()->format('Y-m');

        $dailySpend   = (float) Redis::get(sprintf('cost:daily:%s:%s', $tenantId, $today)) ?: 0;
        $monthlySpend = (float) Redis::get(sprintf('cost:monthly:%s:%s', $tenantId, $month)) ?: 0;

        // Combine with budget info for contextual response
        $status = $this->costGovernor->canExecute($tenantId);

        return response()->json([
            'usage' => [
                'daily_spend'       => round($dailySpend, 4),
                'monthly_spend'     => round($monthlySpend, 4),
                'daily_limit'       => $status['daily_limit'],
                'monthly_limit'     => $status['monthly_limit'],
                'daily_remaining'   => $status['daily_remaining'],
                'monthly_remaining' => $status['monthly_remaining'],
                'daily_pct'         => $status['daily_limit'] > 0
                    ? round($dailySpend / $status['daily_limit'] * 100, 1)
                    : 0,
                'monthly_pct'       => $status['monthly_limit'] > 0
                    ? round($monthlySpend / $status['monthly_limit'] * 100, 1)
                    : 0,
                'alert_triggered'   => $status['alert_triggered'],
                'degraded'          => $status['degraded'],
                'date'              => $today,
                'month'             => $month,
            ],
        ]);
    }

    /**
     * GET /usage/daily
     *
     * Returns v2 canonical contract.
     * Sending X-Usage-Contract: 1 returns HTTP 410 Gone with migration pointer.
     */
    public function dailyUsage(Request $request): JsonResponse
    {
        // Hard-cutover: legacy contract is no longer served
        if ($request->header('X-Usage-Contract') === '1') {
            return response()->json([
                'error'   => 'The v1 usage contract has been retired.',
                'message' => 'Migrate to contract v2. See docs/internal/usage-aggregates-v2.md',
                'docs'    => '/docs/internal/usage-aggregates-v2.md',
            ], 410)->header('X-Usage-Contract', '2');
        }

        $tenantId = $request->attributes->get('tenant_id');
        $days     = (int) $request->query('days', 30);
        $days     = min($days, 90);
        $since    = now()->subDays($days)->toDateString();

        $rows = DB::table('usage_daily_aggregates')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->orderBy('date')
            ->get([
                'date',
                'resource_type',
                'total_calls',
                'total_tokens',
                'total_cost',
                'calculated_at',
            ]);

        // Per-day totals for charting (keyed by date)
        $dailyTotals = $rows->groupBy('date')->map(function ($group, $date) {
            return [
                'date'        => $date,
                'total_calls' => (int) $group->sum('total_calls'),
                'total_tokens'=> (int) $group->sum('total_tokens'),
                'total_cost'  => round($group->sum('total_cost'), 4),
            ];
        })->values();

        return response()->json([
            'contract_version' => '2',
            'daily_usage'      => [
                'breakdown'    => $rows,
                'daily_totals' => $dailyTotals,
                'period_days'  => $days,
            ],
        ])->header('X-Usage-Contract', '2');
    }

    /**
     * GET /usage/monthly
     *
     * Returns v2 canonical contract. Uses to_char() (Postgres-compatible).
     * Sending X-Usage-Contract: 1 returns HTTP 410 Gone.
     */
    public function monthlyUsage(Request $request): JsonResponse
    {
        if ($request->header('X-Usage-Contract') === '1') {
            return response()->json([
                'error'   => 'The v1 usage contract has been retired.',
                'message' => 'Migrate to contract v2. See docs/internal/usage-aggregates-v2.md',
                'docs'    => '/docs/internal/usage-aggregates-v2.md',
            ], 410)->header('X-Usage-Contract', '2');
        }

        $tenantId = $request->attributes->get('tenant_id');
        $months   = (int) $request->query('months', 12);
        $months   = min($months, 24);
        $since    = now()->subMonths($months)->startOfMonth()->toDateString();

        // Postgres-compatible: to_char replaces MySQL's DATE_FORMAT
        $rows = DB::table('usage_daily_aggregates')
            ->where('tenant_id', $tenantId)
            ->where('date', '>=', $since)
            ->selectRaw("to_char(date, 'YYYY-MM') as month")
            ->selectRaw('resource_type')
            ->selectRaw('SUM(total_calls)  as total_calls')
            ->selectRaw('SUM(total_tokens) as total_tokens')
            ->selectRaw('SUM(total_cost)   as total_cost')
            ->groupByRaw("to_char(date, 'YYYY-MM'), resource_type")
            ->orderByRaw("to_char(date, 'YYYY-MM')")
            ->get();

        $monthlyTotals = $rows->groupBy('month')->map(function ($group, $month) {
            return [
                'month'        => $month,
                'total_calls'  => (int)   $group->sum('total_calls'),
                'total_tokens' => (int)   $group->sum('total_tokens'),
                'total_cost'   => round((float) $group->sum('total_cost'), 4),
            ];
        })->values();

        return response()->json([
            'contract_version' => '2',
            'monthly_usage'    => [
                'breakdown'      => $rows,
                'monthly_totals' => $monthlyTotals,
                'period_months'  => $months,
            ],
        ])->header('X-Usage-Contract', '2');
    }

    /**
     * GET /usage/shadow/gate
     * Returns the shadow-diff gate status for the cutover decision.
     * Restricted to internal/admin use (add admin middleware as needed).
     */
    public function shadowGateStatus(): JsonResponse
    {
        return response()->json([
            'shadow_gate' => $this->shadow->gateStatus(),
        ]);
    }
}
