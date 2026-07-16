<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesScript extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'funnel_setup_id', 'version', 'status', 'content',
        'rationale', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'content' => 'array',
        'approved_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function funnelSetup(): BelongsTo
    {
        return $this->belongsTo(FunnelSetup::class);
    }
}
