<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of a skill inside an agent's workspace (plan D3): pins a
 * BrainSnapshot, writes artifacts into the workspace drafts folder, files
 * brain proposals, and parks on waiting_approval / waiting_input / blocked
 * until a human answers. `trigger_ref` makes event and cron triggers
 * replay-idempotent (partial unique index per tenant + skill).
 */
class AgentRun extends Model
{
    use HasUuids;

    public const MODE_SINGLE_SHOT = 'single_shot';

    public const MODE_AGENTIC = 'agentic';

    public const MODES = [self::MODE_SINGLE_SHOT, self::MODE_AGENTIC];

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_EVENT = 'event';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_ATLAS = 'atlas';

    public const TRIGGER_API = 'api';

    public const TRIGGER_RESUME = 'resume';

    public const TRIGGER_TYPES = [
        self::TRIGGER_MANUAL, self::TRIGGER_EVENT, self::TRIGGER_SCHEDULED, self::TRIGGER_ATLAS, self::TRIGGER_API, self::TRIGGER_RESUME,
    ];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING_APPROVAL = 'waiting_approval';

    public const STATUS_WAITING_INPUT = 'waiting_input';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_QUEUED, self::STATUS_CLAIMED, self::STATUS_RUNNING, self::STATUS_WAITING_APPROVAL,
        self::STATUS_WAITING_INPUT, self::STATUS_BLOCKED, self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED,
    ];

    public const TERMINAL = [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** Holding a worker slot (counts toward max_concurrent_per_tenant). */
    public const ACTIVE = [self::STATUS_CLAIMED, self::STATUS_RUNNING];

    /** Parked until a human acts (God's Eye "Needs attention / Needs review"). */
    public const PARKED = [self::STATUS_WAITING_APPROVAL, self::STATUS_WAITING_INPUT, self::STATUS_BLOCKED];

    protected $attributes = [
        'mode' => self::MODE_SINGLE_SHOT,
        'trigger_type' => self::TRIGGER_MANUAL,
        'status' => self::STATUS_QUEUED,
        'tokens' => 0,
        'cost_usd' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'workspace_id', 'agent_id', 'skill_slug', 'mode', 'trigger_type', 'trigger_ref',
        'triggered_by', 'parent_run_id', 'status', 'inputs', 'outputs', 'state', 'brain_snapshot', 'questions',
        'tokens', 'cost_usd', 'claimed_by', 'lease_expires_at', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'inputs' => 'array',
        'outputs' => 'array',
        'state' => 'array',
        'brain_snapshot' => 'array',
        'questions' => 'array',
        'tokens' => 'integer',
        'cost_usd' => 'decimal:6',
        'lease_expires_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE);
    }

    public function scopeParked($query)
    {
        return $query->whereIn('status', self::PARKED);
    }

    public function scopeStale($query)
    {
        return $query->whereIn('status', self::ACTIVE)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', now());
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<AgentWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(AgentWorkspace::class, 'workspace_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_slug', 'slug');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** @return HasMany<AgentRun, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id');
    }

    /** @return HasMany<AgentRunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AgentRunStep::class, 'run_id')->orderBy('seq');
    }

    /** @return HasMany<AgentArtifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(AgentArtifact::class, 'run_id');
    }

    /** @return HasMany<BrainProposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(BrainProposal::class, 'agent_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function isParked(): bool
    {
        return in_array($this->status, self::PARKED, true);
    }

    public function leaseExpired(): bool
    {
        return $this->lease_expires_at !== null && $this->lease_expires_at->isPast();
    }

    /** @return list<array<string, mixed>> the run's proposed follow-on steps (plan D8 "one step further"). */
    public function nextSteps(): array
    {
        return (array) (($this->outputs ?? [])['next_steps'] ?? []);
    }
}
