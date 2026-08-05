<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringBillTemplate extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'vendor_id', 'name', 'amount', 'currency',
        'category_id', 'cadence', 'day_of_month', 'next_run_date',
        'last_period_key', 'autocreate', 'enabled', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'day_of_month' => 'integer',
        'next_run_date' => 'date',
        'autocreate' => 'boolean',
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }
}
