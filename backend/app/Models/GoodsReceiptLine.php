<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_line_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }
}
