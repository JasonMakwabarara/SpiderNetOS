<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentWorkspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * One persistent workspace per tenant x runnable identity (plan D3),
 * created on first skill enable or first run. Idempotent: an existing row
 * for the agent wins; a slug already taken by another agent is suffixed.
 */
final class WorkspaceProvisioner
{
    public function ensure(string $tenantId, string $agentId, string $slug): AgentWorkspace
    {
        $existing = AgentWorkspace::forTenant($tenantId)->where('agent_id', $agentId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $slug = Str::slug($slug !== '' ? $slug : 'agent', '_') ?: 'agent';
        if (AgentWorkspace::forTenant($tenantId)->where('slug', $slug)->exists()) {
            $slug .= '_'.substr(str_replace('-', '', $agentId), 0, 8);
        }

        try {
            return AgentWorkspace::create([
                'tenant_id' => $tenantId,
                'agent_id' => $agentId,
                'slug' => $slug,
                'status' => AgentWorkspace::STATUS_IDLE,
                'pinned_brain_paths' => [],
                'scratch' => [],
                'drafts_root' => AgentWorkspace::defaultDraftsRoot($slug),
                'budget_daily_usd' => (float) config('agents.default_daily_budget_usd', 2.0),
                'spent_today_usd' => 0,
                'settings' => [],
            ]);
        } catch (QueryException $e) {
            // Concurrent provisioning lost the race on (tenant_id, agent_id).
            $existing = AgentWorkspace::forTenant($tenantId)->where('agent_id', $agentId)->first();
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
    }

    /** Board status transitions (Working / Needs attention / Needs review / Idle). */
    public function markStatus(?AgentWorkspace $workspace, string $status, ?string $lastRunId = null): void
    {
        if ($workspace === null || $workspace->isPaused()) {
            return;
        }
        if (! in_array($status, AgentWorkspace::STATUSES, true)) {
            return;
        }

        $update = ['status' => $status, 'last_heartbeat_at' => now(), 'updated_at' => now()];
        if ($lastRunId !== null) {
            $update['last_run_id'] = $lastRunId;
        }

        AgentWorkspace::whereKey($workspace->id)->update($update);
        $workspace->forceFill($update);
    }
}
