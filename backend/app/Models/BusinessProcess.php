<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessProcess extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'system_id', 'name', 'goal',
        'owner_type', 'owner_user_id', 'owner_agent_id',
        'effort_size', 'status', 'position',
        'flow_id', 'schedule_cron', 'last_execution_id', 'last_run_at',
        'last_run_status', 'consecutive_failures', 'needs_attention',
        'escalation_approval_id',
        // Business map: the skill card that does this process (nullable, no FK — see the migration).
        'skill_slug',
    ];

    protected $casts = [
        'effort_size' => 'integer',
        'position' => 'integer',
        'consecutive_failures' => 'integer',
        'needs_attention' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<BusinessSystem, $this>
     */
    public function system(): BelongsTo
    {
        return $this->belongsTo(BusinessSystem::class, 'system_id');
    }

    /**
     * @return HasMany<Sop, $this>
     */
    public function sops(): HasMany
    {
        return $this->hasMany(Sop::class, 'process_id');
    }
}
