<?php

namespace App\Services;

use App\Models\Tenant;

/**
 * Plan-tier gating for background Atlas inference (OpenJarvis bridge).
 *
 * Users never see Jarvis — this gates which agent modes Atlas may invoke server-side.
 */
class JarvisTierGate
{
    /** Agents requiring Growth plan or higher. */
    private const GROWTH_AGENTS = ['morning_digest', 'monitor_operative'];

    /** Agents requiring Enterprise plan. */
    private const ENTERPRISE_AGENTS = ['deep_research'];

    public function planRank(?string $plan): int
    {
        return match ($plan) {
            'enterprise' => 3,
            'growth', 'pro' => 2,
            'free', 'starter', null, '' => 1,
            default => 1,
        };
    }

    public function agentAllowed(string $tenantId, string $agent, ?string $plan = null): bool
    {
        if (! FeatureFlag::on('atlas.openjarvis', $tenantId)) {
            return false;
        }

        $plan ??= $this->resolvePlan($tenantId);
        $rank = $this->planRank($plan);

        if (in_array($agent, self::ENTERPRISE_AGENTS, true)) {
            if (! FeatureFlag::on('atlas.jarvis.deep_research', $tenantId)) {
                return false;
            }

            return $rank >= 3;
        }

        if (in_array($agent, self::GROWTH_AGENTS, true)) {
            if (! FeatureFlag::on('atlas.jarvis.morning_digest', $tenantId)) {
                return false;
            }

            return $rank >= 2;
        }

        return true;
    }

    public function canMorningDigest(string $tenantId, ?string $plan = null): bool
    {
        return $this->agentAllowed($tenantId, 'morning_digest', $plan);
    }

    public function canDeepResearch(string $tenantId, ?string $plan = null): bool
    {
        return $this->agentAllowed($tenantId, 'deep_research', $plan);
    }

    /**
     * Map installed feature-pack verticals to OpenJarvis skill ids.
     *
     * @return array<int, string>
     */
    public function verticalSkillsForTenant(string $tenantId): array
    {
        $skills = [];

        try {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                return $skills;
            }

            foreach ($tenant->featurePacks()->where('status', 'active')->get() as $pack) {
                $vertical = $pack->vertical ?: $pack->pack_id;
                $slug = str_replace('_', '-', (string) $vertical);
                $skills[] = 'spidernet-'.$slug;
            }
        } catch (\Throwable) {
            // Non-fatal — Atlas still works without vertical skills.
        }

        return array_values(array_unique($skills));
    }

    private function resolvePlan(string $tenantId): ?string
    {
        try {
            return Tenant::find($tenantId)?->plan;
        } catch (\Throwable) {
            return null;
        }
    }
}
