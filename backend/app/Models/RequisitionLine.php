<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequisitionLine extends Model
{
    use HasUuids;

    protected $fillable = ['requisition_id', 'description', 'quantity', 'unit_price'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
    ];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }
}
