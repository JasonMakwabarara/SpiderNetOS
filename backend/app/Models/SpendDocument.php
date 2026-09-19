<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SpendDocument extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'uploaded_by', 'kind', 'attachable_type', 'attachable_id',
        'disk', 'path', 'original_filename', 'mime_type', 'size_bytes',
        'sha256', 'status', 'extraction', 'extraction_method',
        'extraction_model', 'extraction_cost_usd', 'extraction_latency_ms',
        'attempts', 'error', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'extraction' => 'array',
        'extraction_cost_usd' => 'decimal:6',
        'extraction_latency_ms' => 'integer',
        'attempts' => 'integer',
        'confirmed_at' => 'datetime',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
