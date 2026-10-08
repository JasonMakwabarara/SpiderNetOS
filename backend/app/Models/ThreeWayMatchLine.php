<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ThreeWayMatchLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'three_way_match_id',
        'purchase_order_line_id',
        'ordered_quantity',
        'received_quantity',
        'invoiced_quantity',
        'status',
    ];

    protected $casts = [
        'ordered_quantity' => 'decimal:4',
        'received_quantity' => 'decimal:4',
        'invoiced_quantity' => 'decimal:4',
    ];
}
