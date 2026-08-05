<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'email', 'phone', 'company', 'tax_id',
        'address', 'currency', 'payment_terms_days', 'default_gl_account_id',
        'bank_details', 'status', 'provider_vendor_id', 'metadata',
    ];

    protected $casts = [
        'address' => 'array',
        'bank_details' => 'array',
        'payment_terms_days' => 'integer',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
