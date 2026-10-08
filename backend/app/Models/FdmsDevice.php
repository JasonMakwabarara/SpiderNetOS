<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FdmsDevice extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id', 'device_id', 'base_url', 'fiscal_day_status', 'fiscal_day_no',
        'fiscal_day_opened_at', 'receipt_counter', 'receipt_global_no',
        'previous_receipt_hash', 'last_receipt_date', 'counters', 'qr_url',
        'applicable_taxes', 'vat_number',
    ];

    protected function casts(): array
    {
        return [
            'device_id' => 'integer',
            'fiscal_day_no' => 'integer',
            'fiscal_day_opened_at' => 'datetime',
            'receipt_counter' => 'integer',
            'receipt_global_no' => 'integer',
            'last_receipt_date' => 'datetime',
            'counters' => 'array',
            'applicable_taxes' => 'array',
        ];
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(FdmsReceipt::class);
    }
}
