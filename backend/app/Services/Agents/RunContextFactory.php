<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\Skill;
use App\Models\Tenant;
use App\Models\TenantSkill;
use App\Services\Agents\Exceptions\SkillNotFoundException;
use App\Services\EventStore;

/**
 * Builds a RunContext for a run row: the card (SkillRegistry, falling back
 * to the seeded `skills` catalogue row), the tenant's skill state, the
 * workspace, the allowlist and — when the run already pinned one — the
 * BrainSnapshot rebuilt from `agent_runs.brain_snapshot`.
 */
final class RunContextFactory
{
    public function __construct(
        private readonly EventStore $events,
        private readonly RunBudget $budget,
    ) {}

    /** @throws SkillNotFoundException */
    public function card(string $slug): SkillCardView
    {
        $registry = Collaborators::skillRegistry();
        if ($registry !== null) {
            $card = $registry->get($slug);
            if ($card !== null) {
                return SkillCardView::from($card);
            }
        }

        // Fallback: the seeded catalogue projection (skills.card jsonb).
        try {
            $row = Skill::query()->whereKey($slug)->first();
        } catch (\Throwable) {
            $row = null;
        }
        if ($row !== null && is_array($row->card) && $row->card !== []) {
            return SkillCardView::from($row->card + ['id' => $row->slug, 'pack_id' => $row->pack_id, 'runs_on' => $row->runs_on]);
        }

        throw new SkillNotFoundException($slug);
    }

    public function forRun(AgentRun $run, ?RunTrace $trace = null, bool $rebuildSnapshot = false): RunContext
    {
        $tenantId = (string) $run->tenant_id;
        $card = $this->card((string) $run->skill_slug);
        $tenantSkill = TenantSkill::forTenant($tenantId)->where('skill_slug', $run->skill_slug)->first();
        $workspace = $run->workspace_id ? AgentWorkspace::find($run->workspace_id) : null;
        $tenant = Tenant::find($tenantId);

        $snapshot = null;
        if ($rebuildSnapshot && is_array($run->brain_snapshot) && $run->brain_snapshot !== []) {
            try {
                $snapshot = Collaborators::snapshotFromArray($tenantId, $run->brain_snapshot);
            } catch (\Throwable) {
                $snapshot = null;
            }
        }

        $requestedBy = $run->triggered_by ? (string) $run->triggered_by : (($run->state ?? [])['requested_by'] ?? null);

        return new RunContext(
            tenantId: $tenantId,
            run: $run,
            workspace: $workspace,
            card: $card,
            snapshot: $snapshot,
            allowlist: RunContext::allowlistFor($card, $tenantSkill),
            budget: $this->budget,
            requestedBy: is_string($requestedBy) ? $requestedBy : null,
            trace: $trace ?? new RunTrace($run, $this->events),
            tenantSkill: $tenantSkill,
            tenant: $tenant,
        );
    }
}
