<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceEnrollment extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'lead_id', 'sequence_key', 'current_step',
        'next_run_at', 'status', 'context',
    ];

    protected $casts = [
        'next_run_at' => 'datetime',
        'context' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeDue($query)
    {
        return $query->where('status', 'active')->where('next_run_at', '<=', now());
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
