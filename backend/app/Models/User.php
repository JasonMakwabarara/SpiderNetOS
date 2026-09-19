<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class User extends Model implements AuthenticatableContract
{
    use Authenticatable, HasApiTokens, HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
        'role',
        'preferences',
        'email_verified_at',
        'last_login_at',
        'step_up_at',
        'capabilities',
        'is_platform_admin',
        'invited_by',
        'invited_at',
        'accepted_at',
        'onboarding_completed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'totp_secret',
    ];

    protected $casts = [
        'preferences' => 'array',
        'capabilities' => 'array',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'step_up_at' => 'datetime',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'is_platform_admin' => 'boolean',
        'password' => 'hashed',
        'totp_secret' => 'encrypted',
        'totp_confirmed_at' => 'datetime',
    ];

    /** True once the user has confirmed a TOTP authenticator (real second factor). */
    public function hasMfaEnrolled(): bool
    {
        return $this->totp_confirmed_at !== null && ! empty($this->totp_secret);
    }

    /** Role hierarchy (lowest → highest). */
    public const ROLE_HIERARCHY = [
        'viewer' => 0,
        'member' => 10,
        'admin' => 20,
        'super_admin' => 100,
    ];

    /** Default capabilities per role. */
    public const ROLE_CAPABILITIES = [
        'viewer' => [
            'dashboard.view', 'flows.view', 'agents.view', 'usage.view', 'copy.view',
        ],
        'member' => [
            'dashboard.view', 'flows.view', 'flows.execute', 'agents.view', 'agents.dispatch',
            'usage.view', 'copy.view', 'atlas.chat', 'command.execute',
        ],
        'admin' => [
            'dashboard.view', 'flows.*', 'agents.*', 'approvals.*', 'usage.*',
            'copy.*', 'atlas.*', 'command.*', 'voice.*',
            'admin.users.view', 'admin.users.manage', 'admin.audit.view', 'admin.copy.manage',
        ],
        'super_admin' => [
            '*', // everything
            'platform.overview', 'platform.flags.manage', 'platform.impersonate',
        ],
    ];

    /** Step-up TTL (sensitive ops must have MFA within this window). */
    public const STEP_UP_TTL_SECONDS = 900; // 15 min

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'super_admin'], true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin' || $this->is_platform_admin === true;
    }

    /** Role rank for atLeastRole() checks. */
    public function roleRank(): int
    {
        return self::ROLE_HIERARCHY[$this->role] ?? 0;
    }

    public function atLeastRole(string $role): bool
    {
        $required = self::ROLE_HIERARCHY[$role] ?? PHP_INT_MAX;

        return $this->roleRank() >= $required;
    }

    /** Resolved capability list (role defaults merged with per-user overrides). */
    public function resolvedCapabilities(): array
    {
        $base = self::ROLE_CAPABILITIES[$this->role] ?? [];
        $overrides = is_array($this->capabilities) ? $this->capabilities : [];

        return array_values(array_unique(array_merge($base, $overrides)));
    }

    /** Wildcard-aware capability check. */
    public function can_do(string $capability): bool
    {
        $caps = $this->resolvedCapabilities();
        if (in_array('*', $caps, true) || in_array($capability, $caps, true)) {
            return true;
        }
        // Wildcard prefix match: e.g. "flows.*" grants "flows.execute"
        $prefix = explode('.', $capability)[0].'.*';

        return in_array($prefix, $caps, true);
    }

    /** True iff MFA step-up is still fresh. */
    public function hasFreshStepUp(): bool
    {
        if (! $this->step_up_at) {
            return false;
        }

        return $this->step_up_at->diffInSeconds(now()) <= self::STEP_UP_TTL_SECONDS;
    }

    public function stepUpSecondsRemaining(): int
    {
        if (! $this->step_up_at) {
            return 0;
        }

        return max(0, self::STEP_UP_TTL_SECONDS - $this->step_up_at->diffInSeconds(now()));
    }
}
