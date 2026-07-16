<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'whatsapp_number',
        'source', 'stage', 'score', 'owner_agent_slug', 'consent',
        'custom', 'last_contacted_at',
    ];

    protected $casts = [
        'consent' => 'array',
        'custom' => 'array',
        'score' => 'integer',
        'last_contacted_at' => 'datetime',
    ];

    public const STAGES = [
        'captured', 'qualified', 'engaged', 'meeting_booked',
        'proposal', 'won', 'lost', 'recycled',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function isOptedIn(string $channel): bool
    {
        if (! empty($this->consent['opted_out_at'])) {
            return false;
        }

        return (bool) ($this->consent[$channel.'_opt_in'] ?? false);
    }
}
