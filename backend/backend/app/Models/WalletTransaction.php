<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'wallet_id', 'tenant_id', 'type', 'amount', 'currency',
        'balance_after', 'reference_type', 'reference_id', 'description', 'status',
    ];
    
    protected $casts = [
        'amount' => 'decimal:4',
        'balance_after' => 'decimal:4',
    ];
    
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
    
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
