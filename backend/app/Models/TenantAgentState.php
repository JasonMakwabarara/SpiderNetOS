<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Circuit-breaker state for one scope of a tenant's Operating brain (plan
 * D8 #6). Written only by AgentCircuitBreaker.
 */
class TenantAgentState extends Model
{
    use HasUuids;

    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_AGENT = 'agent';

    public const SCOPE_SKILL = 'skill';

    public const SCOPE_TOOL_RISK = 'tool_risk';

    public const SCOPES = [self::SCOPE_TENANT, self::SCOPE_AGENT, self::SCOPE_SKILL, self::SCOPE_TOOL_RISK];

    public const STATE_RUNNING = 'running';

    public const STATE_DEMOTED = 'demoted';

    public const STATE_PAUSED = 'paused';

    public const STATES = [self::STATE_RUNNING, self::STATE_DEMOTED, self::STATE_PAUSED];

    public const TRIPPED_BY_HUMAN = 'human';

    public const TRIPPED_BY_TRIPWIRE = 'tripwire';

    protected $attributes = [
        'state' => self::STATE_RUNNING,
        'tripped_by' => self::TRIPPED_BY_HUMAN,
    ];

    protected $fillable = [
        'tenant_id', 'scope', 'scope_id', 'state', 'reason', 'tripped_by', 'tripped_by_user_id',
        'tripped_at', 'resume_at', 'resumed_at', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'tripped_at' => 'datetime',
        'resume_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeTripped($query)
    {
        return $query->whereIn('state', [self::STATE_PAUSED, self::STATE_DEMOTED]);
    }

    public function isRunning(): bool
    {
        return $this->state === self::STATE_RUNNING;
    }

    /** A tripped state whose resume_at has passed counts as running again. */
    public function resumeDue(): bool
    {
        return ! $this->isRunning() && $this->resume_at !== null && $this->resume_at->isPast();
    }

    /** @return array<string, mixed> */
    public function toEnvelope(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'scope_id' => $this->scope_id,
            'state' => $this->state,
            'reason' => $this->reason,
            'tripped_by' => $this->tripped_by,
            'tripped_by_user_id' => $this->tripped_by_user_id,
            'tripped_at' => $this->tripped_at?->toIso8601String(),
            'resume_at' => $this->resume_at?->toIso8601String(),
            'resumed_at' => $this->resumed_at?->toIso8601String(),
            'meta' => (array) $this->meta,
        ];
    }
}
