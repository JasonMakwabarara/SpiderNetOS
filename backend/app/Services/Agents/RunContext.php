<?php

declare(strict_types=1);

namespace App\Services\Agents;

use App\Models\AgentRun;
use App\Models\AgentWorkspace;
use App\Models\Tenant;
use App\Models\TenantSkill;

/**
 * Everything one execution of a skill needs in hand (plan D3): the tenant,
 * the run row, the agent's workspace, the card, the pinned BrainSnapshot,
 * the tool allowlist (card tools ∪ tenant_skills.tool_overrides.allow −
 * deny), the budget guard, who asked, and the trace to write into.
 */
final class RunContext
{
    /** Running totals for this execution (before they are persisted on the run). */
    public float $spentUsd = 0.0;

    public int $spentTokens = 0;

    public function __construct(
        public readonly string $tenantId,
        public readonly AgentRun $run,
        public readonly ?AgentWorkspace $workspace,
        public readonly SkillCardView $card,
        public ?object $snapshot,
        public readonly array $allowlist,
        public readonly RunBudget $budget,
        public readonly ?string $requestedBy,
        public readonly RunTrace $trace,
        public readonly ?TenantSkill $tenantSkill = null,
        public readonly ?Tenant $tenant = null,
    ) {}

    public function agentId(): ?string
    {
        return $this->run->agent_id ? (string) $this->run->agent_id : ($this->workspace?->agent_id ? (string) $this->workspace->agent_id : null);
    }

    public function skillSlug(): string
    {
        return (string) $this->run->skill_slug;
    }

    /** Ladder level for this run: the tenant's setting, optionally lowered by the request. */
    public function autonomy(): string
    {
        $level = $this->tenantSkill?->autonomy_level ?: $this->card->defaultAutonomy();
        $override = (string) (($this->run->state ?? [])['automation_level'] ?? '');

        if ($override !== '' && in_array($override, TenantSkill::LADDER, true)) {
            $rank = array_flip(TenantSkill::LADDER);
            $current = $rank[$level] ?? 0;
            if (($rank[$override] ?? 0) < $current) {
                return $override; // a request may only lower the rung, never raise it
            }
        }

        return (string) $level;
    }

    public function allows(string $tool): bool
    {
        return in_array($tool, $this->allowlist, true);
    }

    public function tenantTier(): string
    {
        return (string) ($this->tenant?->plan ?: 'starter');
    }

    /** @return array<string, mixed> */
    public function inputs(): array
    {
        return (array) ($this->run->inputs ?? []);
    }

    public function draftsRoot(): string
    {
        return $this->workspace?->drafts_root ?: AgentWorkspace::defaultDraftsRoot($this->card->runsOn() ?: 'agent');
    }

    public function addSpend(float $cost, int $tokens = 0): void
    {
        $this->spentUsd += max(0.0, $cost);
        $this->spentTokens += max(0, $tokens);
    }

    /** Cost already charged to this run (persisted + in-flight). */
    public function costSoFar(): float
    {
        return (float) $this->run->cost_usd + $this->spentUsd;
    }

    /**
     * @return list<string>
     */
    public static function allowlistFor(SkillCardView $card, ?TenantSkill $tenantSkill): array
    {
        $overrides = (array) ($tenantSkill?->tool_overrides ?? []);
        $allow = array_map('strval', (array) ($overrides['allow'] ?? []));
        $deny = array_map('strval', (array) ($overrides['deny'] ?? []));

        // The card's post_actions are tool names the author wired in; they
        // are as trusted as tools[] (cold-email-drafting lists drafts.save in
        // tools[] but drafts.save_sequence in run.post_actions).
        $postActionTools = array_values(array_filter($card->postActions(), static fn (string $a) => str_contains($a, '.')));

        return array_values(array_diff(array_unique(array_merge($card->tools(), $postActionTools, $allow)), $deny));
    }
}
