<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseOrder extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'vendor_id',
        'requisition_id',
        'po_number',
        'status',
        'currency',
        'amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('received_at');
    }

    public function supplierInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
