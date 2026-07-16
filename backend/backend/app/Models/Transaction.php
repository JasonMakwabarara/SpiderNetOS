<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'tenant_id', 'transaction_number', 'type', 'amount', 'currency',
        'status', 'payment_method', 'provider', 'provider_reference',
        'source_account_id', 'destination_account_id', 'counterparty_name',
        'counterparty_email', 'description', 'metadata', 'idempotency_key',
        'initiated_at', 'completed_at', 'failed_at', 'failure_reason',
    ];
    
    protected $casts = [
        'amount' => 'decimal:4',
        'metadata' => 'array',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'transaction_id', 'transaction_number');
    }
    
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'transaction_id');
    }
    
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
    
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
    
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
