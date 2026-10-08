<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $device_id
 * @property string|null $base_url
 * @property string $fiscal_day_status
 * @property int|null $fiscal_day_no
 * @property Carbon|null $fiscal_day_opened_at
 * @property int $receipt_counter
 * @property int $receipt_global_no
 * @property string|null $previous_receipt_hash
 * @property Carbon|null $last_receipt_date
 * @property list<array<string, mixed>>|null $counters
 * @property string|null $qr_url
 * @property list<array<string, mixed>>|null $applicable_taxes
 * @property string|null $vat_number
 */
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

    /** @return HasMany<FdmsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(FdmsReceipt::class);
    }
}
