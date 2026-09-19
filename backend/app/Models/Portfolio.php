<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Portfolio extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'description', 'strategy', 'risk_level',
        'currency', 'total_value', 'cash_balance', 'realized_pnl',
        'unrealized_pnl', 'status', 'metadata',
    ];

    protected $casts = [
        'total_value' => 'decimal:4',
        'cash_balance' => 'decimal:4',
        'realized_pnl' => 'decimal:4',
        'unrealized_pnl' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(PortfolioPosition::class);
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getTotalPnl(): float
    {
        return (float) $this->realized_pnl + (float) $this->unrealized_pnl;
    }
}
