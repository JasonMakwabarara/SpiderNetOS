<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\EventStore;
use App\Services\UnifiedAuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private EventStore $eventStore;

    public function __construct(EventStore $eventStore)
    {
        $this->eventStore = $eventStore;
    }

    /**
     * Register a new user + tenant.
     * Events: tenant.created, user.created
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8',
            'organization' => 'nullable|string|max:255',
        ]);

        // Check if email already exists across all tenants
        if (User::where('email', $request->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This email is already registered.'],
            ]);
        }

        // Create tenant via EventStore (Hard Rule #1)
        $tenantId = (string) Str::uuid();
        $orgName = $request->organization ?? $request->name . "'s Workspace";
        $tenantSlug = Str::slug($orgName) . '-' . Str::random(6);

        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'tenant',
            aggregateId: $tenantId,
            eventType: 'tenant.created',
            payload: [
                'name' => $orgName,
                'slug' => $tenantSlug,
                'plan' => 'free',
                'status' => 'active',
            ],
        );

        // Create user via EventStore (Hard Rule #1)
        $userId = (string) Str::uuid();
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'user',
            aggregateId: $userId,
            eventType: 'user.created',
            payload: [
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'tenant_id' => $tenantId,
            ],
        );

        // Read from projection for response
        $user = User::find($userId);
        if (!$user) {
            // Projection may not have caught up yet — create directly as fallback
            $user = User::create([
                'id' => $userId,
                'tenant_id' => $tenantId,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'admin',
            ]);
            
            Tenant::firstOrCreate(['id' => $tenantId], [
                'name' => $orgName,
                'slug' => $tenantSlug,
                'plan' => 'free',
                'status' => 'active',
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant_id' => $user->tenant_id,
            ],
            'token' => $token,
        ], 201);
    }

    /**
     * Login an existing user.
     * Event: user.logged_in
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Record login event
        $this->eventStore->append(
            tenantId: $user->tenant_id,
            aggregateType: 'user',
            aggregateId: $user->id,
            eventType: 'user.logged_in',
            payload: [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        // Update last_login_at
        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json(UnifiedAuthSession::format($user, $token));
    }

    /**
     * Logout the current user.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * Get the authenticated user with role / capabilities / step-up status.
     * Expected by the cockpit role-split frontend.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'tenant_id' => $user->tenant_id,
                'preferences' => $user->preferences,
                'capabilities' => $user->resolvedCapabilities(),
                'step_up' => [
                    'fresh' => $user->hasFreshStepUp(),
                    'seconds_remaining' => $user->stepUpSecondsRemaining(),
                    'at' => $user->step_up_at?->toIso8601String(),
                ],
                'onboarding_completed_at' => $user->onboarding_completed_at?->toIso8601String(),
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
                'onboarding_completed_at' => $tenant->onboarding_completed_at?->toIso8601String(),
                'automation_level' => $tenant->automation_level,
            ] : null,
        ]);
    }

    /**
     * POST /auth/step-up
     *
     * Re-verify the user via password or MFA before performing sensitive ops
     * (impersonation, cutovers, platform flag changes).
     *
     * Body: { password: string, mfa_code?: string }
     */
    public function stepUp(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'mfa_code' => 'nullable|string',
        ]);

        $user = $request->user();

        if (!Hash::check($request->password, $user->password)) {
            $this->recordStepUp($user, $request, false, 'bad_password');
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        // Placeholder MFA check — wire to actual TOTP/WebAuthn in a later pass.
        // For now accept any non-empty mfa_code or skip when not configured.
        if ($request->filled('mfa_code') && strlen($request->mfa_code) < 6) {
            throw ValidationException::withMessages([
                'mfa_code' => ['Invalid MFA code.'],
            ]);
        }

        $user->forceFill(['step_up_at' => now()])->save();
        $this->recordStepUp($user, $request, true);

        return response()->json([
            'step_up' => [
                'fresh' => true,
                'seconds_remaining' => User::STEP_UP_TTL_SECONDS,
                'at' => $user->step_up_at->toIso8601String(),
            ],
        ]);
    }

    private function recordStepUp(User $user, Request $request, bool $success, ?string $reason = null): void
    {
        $this->eventStore->append(
            tenantId: $user->tenant_id,
            aggregateType: 'user',
            aggregateId: $user->id,
            eventType: $success ? 'user.step_up.verified' : 'user.step_up.failed',
            payload: [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'reason' => $reason,
            ],
        );
    }
}
