<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FiscalSubmission extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id', 'fiscal_device_id', 'invoice_id', 'fiscal_day', 'receipt_counter',
        'verification_code', 'qr_payload', 'status', 'driver', 'is_live',
    ];

    protected $casts = [
        'fiscal_day' => 'date',
        'qr_payload' => 'array',
        'is_live' => 'boolean',
    ];
}
