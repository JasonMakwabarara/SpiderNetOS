<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FunnelSetup extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'pack_id', 'status', 'interview_answers', 'current_section',
        'active_script_id', 'approval_id', 'went_live_at',
    ];

    protected $casts = [
        'interview_answers' => 'array',
        'went_live_at' => 'datetime',
    ];

    public const STATUSES = [
        'purchased', 'interviewing', 'script_drafted', 'awaiting_approval',
        'approved', 'live', 'paused', 'rejected',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scripts(): HasMany
    {
        return $this->hasMany(SalesScript::class);
    }

    public function activeScript(): BelongsTo
    {
        return $this->belongsTo(SalesScript::class, 'active_script_id');
    }
}
