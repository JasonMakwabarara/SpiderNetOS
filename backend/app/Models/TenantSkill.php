<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's state for one catalogue skill: enabled, where on the autonomy
 * ladder it sits, tool overrides, budget and the promotion-gate counter.
 * "Provided when needed": a catalogue skill without a row shows Enable.
 */
class TenantSkill extends Model
{
    use HasUuids;

    public const AUTONOMY_HUMAN_LED = 'human_led';

    public const AUTONOMY_ASSISTED = 'assisted';

    public const AUTONOMY_AUTONOMOUS = 'autonomous';

    /** D8 #9: runs as assisted, records would_have, scores agreement. */
    public const AUTONOMY_SHADOW = 'shadow';

    public const AUTONOMY_LEVELS = [
        self::AUTONOMY_HUMAN_LED, self::AUTONOMY_ASSISTED, self::AUTONOMY_AUTONOMOUS, self::AUTONOMY_SHADOW,
    ];

    /** Ladder order for promotion/demotion (shadow sits beside assisted). */
    public const LADDER = [self::AUTONOMY_HUMAN_LED, self::AUTONOMY_ASSISTED, self::AUTONOMY_AUTONOMOUS];

    protected $attributes = [
        'enabled' => false,
        'autonomy_level' => self::AUTONOMY_HUMAN_LED,
        'clean_drafts_count' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'skill_slug', 'agent_id', 'workspace_id', 'enabled', 'autonomy_level', 'tool_overrides',
        'budget_daily_usd', 'state', 'clean_drafts_count', 'enabled_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'tool_overrides' => 'array',
        'state' => 'array',
        'budget_daily_usd' => 'decimal:4',
        'clean_drafts_count' => 'integer',
        'enabled_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_slug', 'slug');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<AgentWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(AgentWorkspace::class, 'workspace_id');
    }

    public function isAutonomous(): bool
    {
        return $this->autonomy_level === self::AUTONOMY_AUTONOMOUS;
    }
}
