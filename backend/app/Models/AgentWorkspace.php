<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Each agent has its own persistent workspace (plan D3): one row per
 * tenant x runnable identity, created on skill enable. Runs are sessions
 * inside it; all of a tenant's workspaces run concurrently on the same
 * Knowledge brain. Only the shared brain is write-guarded — scratch is the
 * agent's own.
 */
class AgentWorkspace extends Model
{
    use HasUuids;

    public const STATUS_IDLE = 'idle';

    public const STATUS_WORKING = 'working';

    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_PAUSED = 'paused';

    public const STATUSES = [
        self::STATUS_IDLE, self::STATUS_WORKING, self::STATUS_NEEDS_ATTENTION, self::STATUS_NEEDS_REVIEW, self::STATUS_PAUSED,
    ];

    protected $attributes = [
        'status' => self::STATUS_IDLE,
        'spent_today_usd' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'agent_id', 'slug', 'status', 'pinned_brain_paths', 'scratch', 'drafts_root',
        'budget_daily_usd', 'spent_today_usd', 'spent_day', 'last_run_id', 'last_heartbeat_at',
        'paused_at', 'settings',
    ];

    protected $casts = [
        'pinned_brain_paths' => 'array',
        'scratch' => 'array',
        'settings' => 'array',
        'budget_daily_usd' => 'decimal:4',
        'spent_today_usd' => 'decimal:4',
        'spent_day' => 'date',
        'last_heartbeat_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeRunnable($query)
    {
        return $query->where('status', '!=', self::STATUS_PAUSED);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'workspace_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'last_run_id');
    }

    /** @return HasMany<AgentArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(AgentArtifact::class, 'workspace_id');
    }

    /** @return HasMany<TenantSkill, $this> */
    public function tenantSkills(): HasMany
    {
        return $this->hasMany(TenantSkill::class, 'workspace_id');
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    /** Brain-tree prefix under which this workspace's scratch is exposed. */
    public function scratchPath(): string
    {
        return "workspaces/{$this->slug}/scratch";
    }

    public static function defaultDraftsRoot(string $slug): string
    {
        return "workspaces/{$slug}/drafts";
    }

    /** Remaining daily budget, treating a stale spent_day as a fresh day. */
    public function remainingBudgetUsd(): float
    {
        $spent = $this->spent_day && $this->spent_day->isToday() ? (float) $this->spent_today_usd : 0.0;

        return max(0.0, (float) $this->budget_daily_usd - $spent);
    }
}
