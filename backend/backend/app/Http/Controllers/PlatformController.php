<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\Event;
use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * PlatformController — cross-tenant platform operator endpoints.
 * Super-admin only. Step-up MFA required for all mutations.
 */
class PlatformController extends Controller
{
    // ─── Overview ────────────────────────────────────────────────────────────

    /** GET /api/platform/overview */
    public function overview(Request $request): JsonResponse
    {
        $tenantCount = Tenant::count();
        $activeTenants = Tenant::where('status', 'active')->count();
        $userCount = User::count();
        $eventsLast24h = Event::where('occurred_at', '>=', now()->subDay())->count();

        // Usage snapshot from daily aggregates
        $today = now()->toDateString();
        $todayCalls = DB::table('usage_daily_aggregates')
            ->where('date', $today)
            ->sum('total_calls');
        $todayTokens = DB::table('usage_daily_aggregates')
            ->where('date', $today)
            ->sum('total_tokens');
        $todayCost = DB::table('usage_daily_aggregates')
            ->where('date', $today)
            ->sum('total_cost');

        // Flag snapshot for rollout visibility
        $flags = FeatureFlag::all();

        return response()->json([
            'tenants' => [
                'total' => $tenantCount,
                'active' => $activeTenants,
            ],
            'users' => [
                'total' => $userCount,
            ],
            'activity' => [
                'events_last_24h' => $eventsLast24h,
            ],
            'usage_today' => [
                'total_calls' => (int) $todayCalls,
                'total_tokens' => (int) $todayTokens,
                'total_cost' => (float) $todayCost,
            ],
            'flags' => $flags,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    // ─── Feature Flags ───────────────────────────────────────────────────────

    /** GET /api/platform/feature-flags */
    public function listFlags(Request $request): JsonResponse
    {
        $flags = FeatureFlag::all();

        // Return structured entries for the cockpit UI (PlatformFeatureFlags.vue)
        $out = [];
        foreach ($flags as $name => $value) {
            $out[] = [
                'name' => $name,
                'value' => $value,
                'resolved' => $value,
                'source' => $this->flagSource($name),
            ];
        }

        return response()->json(['data' => $out]);
    }

    /** GET /api/platform/feature-flags/{name} */
    public function getFlag(Request $request, string $name): JsonResponse
    {
        $tenantId = $request->input('tenant_id');
        $value = FeatureFlag::value($name, $tenantId);

        return response()->json([
            'name' => $name,
            'value' => $value,
            'tenant_id' => $tenantId,
            'source' => $this->flagSource($name, $tenantId),
        ]);
    }

    /** PUT /api/platform/feature-flags/{name} */
    public function putFlag(Request $request, string $name): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'value' => 'required',
            'tenant_id' => 'nullable|uuid',
        ]);

        // Coerce booleans / scalars to string for Redis storage
        $value = is_bool($validated['value'])
            ? ($validated['value'] ? 'on' : 'off')
            : (string) $validated['value'];

        FeatureFlag::set($name, $value, $validated['tenant_id'] ?? null);

        AdminAuditLog::record(
            tenantId: $validated['tenant_id'] ?? null,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'flag.toggled',
            targetType: 'feature_flag',
            targetId: $name,
            payload: ['value' => $value, 'tenant_id' => $validated['tenant_id'] ?? null],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'name' => $name,
            'value' => $value,
            'tenant_id' => $validated['tenant_id'] ?? null,
        ]);
    }

    /** DELETE /api/platform/feature-flags/{name} */
    public function deleteFlag(Request $request, string $name): JsonResponse
    {
        $actor = $request->user();
        $tenantId = $request->input('tenant_id');

        FeatureFlag::forget($name, $tenantId);

        AdminAuditLog::record(
            tenantId: $tenantId,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'flag.cleared',
            targetType: 'feature_flag',
            targetId: $name,
            payload: ['tenant_id' => $tenantId],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(null, 204);
    }

    // ─── Impersonation ──────────────────────────────────────────────────────

    /** POST /api/platform/impersonate */
    public function impersonate(Request $request): JsonResponse
    {
        $actor = $request->user();

        $validated = $request->validate([
            'target_user_id' => 'required|uuid|exists:users,id',
            'reason' => 'required|string|min:10|max:500',
            'duration_minutes' => 'nullable|integer|min:5|max:240',
        ]);

        $target = User::findOrFail($validated['target_user_id']);

        // Prevent impersonating other super admins
        if ($target->isSuperAdmin()) {
            return response()->json([
                'error' => 'Forbidden',
                'reason' => 'cannot_impersonate_super_admin',
            ], 403);
        }

        $duration = $validated['duration_minutes'] ?? 30;

        $session = ImpersonationSession::create([
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'target_tenant_id' => $target->tenant_id,
            'reason' => $validated['reason'],
            'started_at' => now(),
            'expires_at' => now()->addMinutes($duration),
            'metadata' => [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);

        // Issue a scoped Sanctum token for the target user
        $token = $target->createToken(
            name: 'impersonation:' . $session->id,
            abilities: ['impersonated'],
            expiresAt: $session->expires_at,
        )->plainTextToken;

        AdminAuditLog::record(
            tenantId: $target->tenant_id,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'impersonate.started',
            targetType: 'user',
            targetId: $target->id,
            payload: [
                'session_id' => $session->id,
                'reason' => $validated['reason'],
                'duration_minutes' => $duration,
            ],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'session_id' => $session->id,
            'token' => $token,
            'target' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
                'tenant_id' => $target->tenant_id,
            ],
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }

    /** POST /api/platform/impersonate/{id}/end */
    public function endImpersonation(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $session = ImpersonationSession::findOrFail($id);

        if ($session->actor_user_id !== $actor->id && !$actor->isSuperAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $session->ended_at = now();
        $session->save();

        AdminAuditLog::record(
            tenantId: $session->target_tenant_id,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'impersonate.ended',
            targetType: 'impersonation_session',
            targetId: $session->id,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => $session]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function flagSource(string $name, ?string $tenantId = null): string
    {
        try {
            if ($tenantId && Redis::exists('feature:' . $name . ':tenant:' . $tenantId)) {
                return 'redis:tenant';
            }
            if (Redis::exists('feature:' . $name)) {
                return 'redis:global';
            }
        } catch (\Throwable) {
            // fallthrough
        }

        $allFlags = (array) config('features', []);
        if (array_key_exists($name, $allFlags)) {
            return 'config';
        }

        return 'default';
    }
}
