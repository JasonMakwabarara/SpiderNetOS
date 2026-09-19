<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\EventStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AdminController — tenant-scoped admin workspace endpoints.
 *
 * All routes sit behind auth:sanctum + tenant + role:admin middleware stack.
 * Sensitive mutations additionally require step.up middleware.
 */
class AdminController extends Controller
{
    public function __construct(private EventStore $eventStore) {}

    // ─── Users ───────────────────────────────────────────────────────────────

    /** GET /api/admin/users */
    public function listUsers(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $perPage = min((int) $request->input('per_page', 25), 100);

        $query = User::where('tenant_id', $tenantId);

        if ($q = $request->input('q')) {
            $query->where(function ($b) use ($q) {
                $b->where('name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%");
            });
        }
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /** POST /api/admin/users:invite  (also: POST /api/admin/users) */
    public function inviteUser(Request $request): JsonResponse
    {
        $actor = $request->user();

        $request->validate([
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'role' => 'required|string|in:viewer,member,admin',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string',
        ]);

        // Prevent privilege escalation: admins cannot invite super_admins
        if (! $actor->isSuperAdmin() && $request->role === 'super_admin') {
            throw ValidationException::withMessages([
                'role' => ['Only super admins can grant the super_admin role.'],
            ]);
        }

        $tenantId = $actor->tenant_id;

        // Per-tenant email uniqueness
        $existing = User::where('tenant_id', $tenantId)->where('email', $request->email)->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email already exists in this workspace.'],
            ]);
        }

        $userId = (string) Str::uuid();
        $tempPassword = Str::random(24);

        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'user',
            aggregateId: $userId,
            eventType: 'user.invited',
            payload: [
                'name' => $request->name,
                'email' => $request->email,
                'role' => $request->role,
                'capabilities' => $request->input('capabilities'),
                'invited_by' => $actor->id,
                'tenant_id' => $tenantId,
            ],
        );

        // Fallback projection create
        $user = User::firstOrCreate(
            ['id' => $userId],
            [
                'tenant_id' => $tenantId,
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($tempPassword),
                'role' => $request->role,
                'capabilities' => $request->input('capabilities'),
                'invited_by' => $actor->id,
                'invited_at' => now(),
            ]
        );

        AdminAuditLog::record(
            tenantId: $tenantId,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'user.invited',
            targetType: 'user',
            targetId: $userId,
            payload: ['email' => $request->email, 'role' => $request->role],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'user' => $user,
            // Temp password is included so the UI can present an invite link.
            // Real implementation would email a reset token instead.
            'temp_password' => $tempPassword,
        ], 201);
    }

    /** DELETE /api/admin/users/{id} */
    public function deleteUser(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $tenantId = $actor->tenant_id;

        if ($id === $actor->id) {
            return response()->json(['error' => 'You cannot delete your own account.'], 422);
        }

        $user = User::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();

        // Admins can't delete other admins or super_admins; only super_admins can.
        if (! $actor->isSuperAdmin() && $user->atLeastRole('admin')) {
            return response()->json([
                'error' => 'Forbidden',
                'reason' => 'cannot_delete_admin',
            ], 403);
        }

        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'user',
            aggregateId: $user->id,
            eventType: 'user.deleted',
            payload: ['email' => $user->email, 'deleted_by' => $actor->id],
        );

        // Revoke all tokens before deleting
        $user->tokens()->delete();
        $user->delete();

        AdminAuditLog::record(
            tenantId: $tenantId,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'user.deleted',
            targetType: 'user',
            targetId: $id,
            payload: ['email' => $user->email],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(null, 204);
    }

    /** PATCH /api/admin/users/{id} — update role / capabilities */
    public function updateUser(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $tenantId = $actor->tenant_id;

        $user = User::where('tenant_id', $tenantId)->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'role' => 'nullable|string|in:viewer,member,admin,super_admin',
            'capabilities' => 'nullable|array',
            'capabilities.*' => 'string',
            'name' => 'nullable|string|max:255',
        ]);

        if (isset($validated['role']) && $validated['role'] === 'super_admin' && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'role' => ['Only super admins can grant the super_admin role.'],
            ]);
        }

        $user->update($validated);

        AdminAuditLog::record(
            tenantId: $tenantId,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'user.updated',
            targetType: 'user',
            targetId: $id,
            payload: $validated,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => $user->fresh()]);
    }

    // ─── Audit Log ───────────────────────────────────────────────────────────

    /** GET /api/admin/audit */
    public function audit(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $perPage = min((int) $request->input('per_page', 50), 200);

        $query = AdminAuditLog::where('tenant_id', $tenantId);

        if ($action = $request->input('action')) {
            $query->where('action', $action);
        }
        if ($actorId = $request->input('actor_id')) {
            $query->where('actor_id', $actorId);
        }
        if ($since = $request->input('since')) {
            $query->where('created_at', '>=', $since);
        }
        if ($until = $request->input('until')) {
            $query->where('created_at', '<=', $until);
        }

        $logs = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    // ─── Atlas Copy State ────────────────────────────────────────────────────

    /** GET /api/admin/copy/state — per-surface enablement */
    public function getCopyState(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Persist in tenants.settings.copy_surfaces (jsonb).
        $tenant = $request->user()->tenant;
        $state = $tenant?->settings['copy_surfaces'] ?? null;

        // Default surface list (matches cockpit AdminCopy.vue expectations)
        $defaults = [
            'empty_state' => ['enabled' => true, 'shadow' => false],
            'banner' => ['enabled' => true, 'shadow' => false],
            'modal' => ['enabled' => true, 'shadow' => false],
            'tooltip' => ['enabled' => true, 'shadow' => false],
            'success_state' => ['enabled' => true, 'shadow' => false],
            'error_state' => ['enabled' => true, 'shadow' => false],
        ];

        return response()->json([
            'tenant_id' => $tenantId,
            'surfaces' => $state ?: $defaults,
        ]);
    }

    /** PUT /api/admin/copy/state */
    public function putCopyState(Request $request): JsonResponse
    {
        $actor = $request->user();
        $tenantId = $actor->tenant_id;

        $validated = $request->validate([
            'surfaces' => 'required|array',
            'surfaces.*.enabled' => 'boolean',
            'surfaces.*.shadow' => 'boolean',
        ]);

        // Persist to tenants.settings.copy_surfaces
        $tenant = $actor->tenant;
        if ($tenant) {
            $settings = $tenant->settings ?? [];
            $settings['copy_surfaces'] = $validated['surfaces'];
            $tenant->settings = $settings;
            $tenant->save();
        }

        AdminAuditLog::record(
            tenantId: $tenantId,
            actorId: $actor->id,
            actorEmail: $actor->email,
            action: 'copy.state_updated',
            targetType: 'tenant',
            targetId: $tenantId,
            payload: $validated['surfaces'],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'tenant_id' => $tenantId,
            'surfaces' => $validated['surfaces'],
        ]);
    }
}
