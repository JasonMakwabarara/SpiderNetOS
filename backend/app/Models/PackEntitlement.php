<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PackEntitlement extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'pack_id', 'source', 'provider', 'provider_payment_id',
        'provider_subscription_id', 'provider_customer_id', 'status',
        'amount_cents', 'currency', 'purchased_at', 'expires_at', 'raw_payload',
    ];

    protected $casts = [
        'purchased_at' => 'datetime',
        'expires_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
