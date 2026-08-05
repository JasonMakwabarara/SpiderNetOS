<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'tenant_id', 'invoice_id', 'transaction_id', 'payment_number',
        'type', 'amount', 'currency', 'method', 'status', 'provider',
        'provider_reference', 'reference_number', 'idempotency_key',
        'notes', 'metadata', 'paid_at',
    ];
    
    protected $casts = [
        'amount' => 'decimal:4',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
    
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
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
