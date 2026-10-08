<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CreditNoteLineItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'credit_note_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'tax_rate' => 'decimal:4',
        'total' => 'decimal:4',
    ];
}
