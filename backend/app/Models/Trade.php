<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends Model
{
    use HasUuids;

    protected $fillable = [
        'portfolio_id', 'tenant_id', 'order_id', 'asset_type', 'symbol',
        'side', 'quantity', 'price', 'commission', 'total_value',
        'status', 'provider', 'provider_reference', 'notes', 'metadata',
        'executed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'price' => 'decimal:4',
        'commission' => 'decimal:4',
        'total_value' => 'decimal:4',
        'executed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
