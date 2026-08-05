<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

/**
 * Single canonical session shape for React landing + Vue cockpit.
 * Laravel Sanctum is the source of truth; enterprise FastAPI is optional for SSO/SCIM only.
 */
class UnifiedAuthSession
{
    public static function format(User $user, string $token): array
    {
        $tenant = $user->tenant;

        return [
            'access_token' => $token,
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => self::userPayload($user),
            'tenant' => self::tenantPayload($tenant),
            'caps' => $user->resolvedCapabilities(),
            'capabilities' => $user->resolvedCapabilities(),
        ];
    }

    public static function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_platform_admin' => (bool) $user->is_platform_admin,
            'tenant_id' => $user->tenant_id,
            'onboarding_completed_at' => $user->onboarding_completed_at?->toIso8601String(),
            'capabilities' => $user->resolvedCapabilities(),
        ];
    }

    public static function tenantPayload(?Tenant $tenant): ?array
    {
        if (!$tenant) {
            return null;
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'plan' => $tenant->plan,
            'status' => $tenant->status,
            'automation_level' => $tenant->automation_level,
            'onboarding_completed_at' => $tenant->onboarding_completed_at?->toIso8601String(),
        ];
    }
}
