<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agents;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\TenantSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/agent-workspaces — the God's Eye board, server-side: one row per
 * agent with status, budget, run counts and pending reviews; the detail
 * view adds recent runs, drafts, scratch and the skills it runs.
 */
class AgentWorkspaceController extends AgentsController
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $workspaces = AgentWorkspace::forTenant($tenantId)->with('agent')->orderBy('slug')->get();

        $runCounts = AgentRun::forTenant($tenantId)
            ->whereNotNull('workspace_id')
            ->select('workspace_id', 'status', DB::raw('count(*) as n'))
            ->groupBy('workspace_id', 'status')
            ->get()
            ->groupBy('workspace_id');

        // One review = one approval (a sequence and its step emails share it).
        $pendingReviews = AgentArtifact::forTenant($tenantId)
            ->where('status', AgentArtifact::STATUS_SUBMITTED)
            ->whereNotNull('workspace_id')
            ->select('workspace_id', DB::raw('count(distinct coalesce(approval_id, id)) as n'))
            ->groupBy('workspace_id')
            ->pluck('n', 'workspace_id');

        $skills = TenantSkill::forTenant($tenantId)->whereNotNull('workspace_id')->get()->groupBy('workspace_id');

        $data = $workspaces->map(function (AgentWorkspace $ws) use ($runCounts, $pendingReviews, $skills) {
            $counts = [];
            foreach ($runCounts->get($ws->id, collect()) as $row) {
                $counts[$row->status] = (int) $row->n;
            }

            return $this->workspacePayload($ws) + [
                'run_counts' => $counts,
                'active_runs' => ($counts[AgentRun::STATUS_CLAIMED] ?? 0) + ($counts[AgentRun::STATUS_RUNNING] ?? 0),
                'pending_reviews' => (int) ($pendingReviews[$ws->id] ?? 0),
                'skills' => $skills->get($ws->id, collect())->map(fn (TenantSkill $s) => [
                    'skill_slug' => $s->skill_slug, 'enabled' => (bool) $s->enabled, 'autonomy_level' => $s->autonomy_level,
                    'clean_drafts_count' => (int) $s->clean_drafts_count,
                ])->values()->all(),
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $workspace = AgentWorkspace::forTenant($tenantId)->with('agent')->where('slug', $slug)->first()
            ?? AgentWorkspace::forTenant($tenantId)->with('agent')->find($slug);
        if ($workspace === null) {
            return response()->json(['error' => 'workspace_not_found'], 404);
        }

        $runs = AgentRun::forTenant($tenantId)->where('workspace_id', $workspace->id)->orderByDesc('created_at')->limit(10)->get();
        $drafts = AgentArtifact::forTenant($tenantId)->where('workspace_id', $workspace->id)
            ->whereIn('status', [AgentArtifact::STATUS_DRAFT, AgentArtifact::STATUS_SUBMITTED])
            ->orderByDesc('created_at')->limit(20)->get();
        $skills = TenantSkill::forTenant($tenantId)->where('workspace_id', $workspace->id)->get();

        return response()->json(['data' => $this->workspacePayload($workspace) + [
            'scratch' => (array) ($workspace->scratch ?? []),
            'settings' => (array) ($workspace->settings ?? []),
            'recent_runs' => $runs->map(fn (AgentRun $r) => $this->runPayload($r))->values()->all(),
            'drafts' => $drafts->map(fn (AgentArtifact $a) => $this->artifactPayload($a, false))->values()->all(),
            'pending_reviews' => $drafts->where('status', AgentArtifact::STATUS_SUBMITTED)
                ->map(fn (AgentArtifact $a) => $a->approval_id ?? $a->id)->unique()->count(),
            'skills' => $skills->map(fn (TenantSkill $s) => [
                'skill_slug' => $s->skill_slug, 'enabled' => (bool) $s->enabled, 'autonomy_level' => $s->autonomy_level,
                'clean_drafts_count' => (int) $s->clean_drafts_count, 'budget_daily_usd' => $s->budget_daily_usd,
                'state' => (array) ($s->state ?? []),
            ])->values()->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function workspacePayload(AgentWorkspace $ws): array
    {
        return [
            'id' => $ws->id,
            'slug' => $ws->slug,
            'status' => $ws->status,
            'agent' => $ws->agent ? ['id' => $ws->agent->id, 'name' => $ws->agent->name, 'slug' => $ws->agent->slug, 'status' => $ws->agent->status] : null,
            'drafts_root' => $ws->drafts_root,
            'scratch_path' => $ws->scratchPath(),
            'pinned_brain_paths' => (array) ($ws->pinned_brain_paths ?? []),
            'budget_daily_usd' => (float) $ws->budget_daily_usd,
            'spent_today_usd' => $ws->spent_day && $ws->spent_day->isToday() ? (float) $ws->spent_today_usd : 0.0,
            'remaining_budget_usd' => $ws->remainingBudgetUsd(),
            'last_run_id' => $ws->last_run_id,
            'last_heartbeat_at' => $ws->last_heartbeat_at?->toIso8601String(),
            'paused_at' => $ws->paused_at?->toIso8601String(),
            'created_at' => $ws->created_at?->toIso8601String(),
            'updated_at' => $ws->updated_at?->toIso8601String(),
        ];
    }
}
