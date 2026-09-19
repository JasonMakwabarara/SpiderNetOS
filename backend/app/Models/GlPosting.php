<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlPosting extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'source_type', 'source_id', 'transaction_number',
        'status', 'amount', 'currency', 'lines', 'error', 'posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'lines' => 'array',
        'posted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForSource($query, string $sourceType, string $sourceId)
    {
        return $query->where('source_type', $sourceType)->where('source_id', $sourceId);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
