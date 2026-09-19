<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Bill extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'vendor_id', 'bill_number', 'vendor_invoice_ref',
        'status', 'subtotal', 'tax_amount', 'total_amount', 'currency',
        'issue_date', 'due_date', 'scheduled_for', 'paid_at', 'void_at',
        'approval_id', 'payment_id', 'source', 'notes', 'terms', 'metadata',
    ];

    protected $casts = [
        'subtotal' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'issue_date' => 'date',
        'due_date' => 'date',
        'scheduled_for' => 'date',
        'paid_at' => 'date',
        'void_at' => 'date',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(BillLineItem::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(SpendDocument::class, 'attachable');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function paymentInstructions(): HasMany
    {
        return $this->hasMany(PaymentInstruction::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereNotIn('status', ['paid', 'void']);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
