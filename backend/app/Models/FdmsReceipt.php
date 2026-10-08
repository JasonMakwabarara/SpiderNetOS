<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $fdms_device_id
 * @property string $invoice_id
 * @property string|null $credit_note_id
 * @property string $receipt_type
 * @property int $fiscal_day_no
 * @property int $receipt_counter
 * @property int $receipt_global_no
 * @property string $receipt_hash
 * @property string $receipt_signature
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int|null $fdms_receipt_id
 * @property string|null $operation_id
 * @property Carbon|null $server_date
 * @property string|null $qr_data
 * @property list<array<string, mixed>>|null $validation_errors
 * @property string|null $error_code
 */
class FdmsReceipt extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id', 'fdms_device_id', 'invoice_id', 'credit_note_id', 'receipt_type', 'fiscal_day_no',
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

    /** @return BelongsTo<FdmsDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(FdmsDevice::class, 'fdms_device_id');
    }
}
