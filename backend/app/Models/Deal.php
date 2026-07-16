<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deal extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'lead_id', 'value_cents', 'currency',
        'stage', 'expected_close_at', 'closed_at',
    ];

    protected $casts = [
        'value_cents' => 'integer',
        'expected_close_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
