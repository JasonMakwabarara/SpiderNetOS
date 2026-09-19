<?php

namespace App\Services\Onboarding;

use App\Models\User;

/**
 * Soft gate (Layer C) — STE-aware policy reshaping
 *
 * Determines if the user should have their action space narrowed
 * to prioritize onboarding completion.
 */
class OnboardingPolicy
{
    /**
     * Determine the override policy for a given user
     *
     * @return string|null 'onboarding_priority' or null
     */
    public function overrideFor(User $user): ?string
    {
        if ($user->onboarding_completed_at) {
            return null;
        }

        return 'onboarding_priority';
    }

    /**
     * Check if user is in onboarding flow
     */
    public function isInOnboarding(User $user): bool
    {
        return $user->onboarding_completed_at === null;
    }

    /**
     * Log policy exposure for causal inference baseline
     *
     * This creates the baseline for measuring the effect
     * of the onboarding_priority policy when it's later activated.
     */
    public function logExposure(string $tenantId, string $userId, ?string $policy): array
    {
        return [
            'event_type' => 'policy.exposed',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'payload' => [
                'policy' => $policy,
                'active' => false,  // Baseline: not yet acting on it
                'source' => 'onboarding_soft_gate',
            ],
            'metadata' => [
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
