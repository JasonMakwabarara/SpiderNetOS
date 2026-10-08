<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdmsReceipt extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id', 'fdms_device_id', 'invoice_id', 'receipt_type', 'fiscal_day_no',
        'receipt_counter', 'receipt_global_no', 'receipt_hash', 'receipt_signature',
        'payload', 'status', 'fdms_receipt_id', 'operation_id', 'server_date',
        'qr_data', 'validation_errors', 'error_code',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_day_no' => 'integer',
            'receipt_counter' => 'integer',
            'receipt_global_no' => 'integer',
            'payload' => 'array',
            'fdms_receipt_id' => 'integer',
            'server_date' => 'datetime',
            'validation_errors' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(FdmsDevice::class, 'fdms_device_id');
    }
}
