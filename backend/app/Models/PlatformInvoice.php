<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform invoice for one tenant + billing period: flat platform fee plus
 * metered usage overage. Unique per (tenant, period).
 */
class PlatformInvoice extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'plan_id', 'period_start', 'period_end',
        'platform_fee_cents', 'included_usage_cents', 'metered_usage_cents',
        'overage_cents', 'total_cents', 'currency', 'status',
        'provider_payment_id', 'issued_at', 'paid_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'platform_fee_cents' => 'integer',
        'included_usage_cents' => 'integer',
        'metered_usage_cents' => 'integer',
        'overage_cents' => 'integer',
        'total_cents' => 'integer',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PlatformInvoiceLine::class, 'invoice_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
