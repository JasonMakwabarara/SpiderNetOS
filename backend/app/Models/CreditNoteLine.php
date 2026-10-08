<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'credit_note_id', 'description', 'quantity', 'unit_price', 'line_total',
        'invoice_line_item_id', 'tax_rate', 'discount_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'discount_amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<InvoiceLineItem, $this> */
    public function invoiceLineItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceLineItem::class);
    }

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }
}
