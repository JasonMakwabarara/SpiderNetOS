<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CashMovement extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id', 'cashbook_id', 'type', 'amount', 'currency', 'movement_date',
        'reference', 'description', 'invoice_id', 'ledger_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'movement_date' => 'date',
    ];
}
