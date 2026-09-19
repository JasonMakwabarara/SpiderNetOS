<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioPosition extends Model
{
    use HasUuids;

    protected $fillable = [
        'portfolio_id', 'tenant_id', 'asset_type', 'symbol', 'name',
        'quantity', 'avg_cost', 'current_price', 'market_value',
        'unrealized_pnl', 'realized_pnl', 'weight', 'last_price_update',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
        'avg_cost' => 'decimal:4',
        'current_price' => 'decimal:4',
        'market_value' => 'decimal:4',
        'unrealized_pnl' => 'decimal:4',
        'realized_pnl' => 'decimal:4',
        'weight' => 'decimal:2',
        'last_price_update' => 'datetime',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function updatePrice(float $price): void
    {
        $this->current_price = $price;
        $this->market_value = $this->quantity * $price;
        $this->unrealized_pnl = ($price - $this->avg_cost) * $this->quantity;
        $this->last_price_update = now();
        $this->save();
    }
}
