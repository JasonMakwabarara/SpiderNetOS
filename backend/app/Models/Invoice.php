<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;
    
    protected $fillable = [
        'tenant_id', 'invoice_number', 'customer_id', 'customer_name',
        'customer_email', 'subtotal', 'tax_amount', 'discount_amount',
        'total_amount', 'currency', 'status', 'issue_date', 'due_date',
        'paid_at', 'cancelled_at', 'notes', 'terms', 'pdf_path', 'metadata',
    ];
    
    protected $casts = [
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'date',
        'cancelled_at' => 'date',
        'metadata' => 'array',
    ];
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
    
    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class);
    }
    
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
    
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
    
    public function scopeOverdue($query)
    {
        return $query->where('status', '!=', 'paid')
            ->where('due_date', '<', now()->toDateString());
    }
    
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }
    
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }
    
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
}
