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

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return BelongsTo<Requisition, $this> */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('received_at');
    }

    /** @return HasOne<Invoice, $this> */
    public function supplierInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }
}
