<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A purchasable platform plan (catalog). String PK: launch|growth|enterprise.
 * Seeded by PlanCatalogSeeder; `entitlements` holds {agents, flows, seats,
 * pack_slots} where -1 means unlimited.
 */
class Plan extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'name', 'tagline', 'monthly_fee_cents', 'included_usage_cents',
        'currency', 'dodo_product_id', 'usage_margin_pct', 'is_custom',
        'entitlements', 'is_active', 'sort',
    ];

    protected $casts = [
        'monthly_fee_cents' => 'integer',
        'included_usage_cents' => 'integer',
        'usage_margin_pct' => 'integer',
        'is_custom' => 'boolean',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'entitlements' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * A single entitlement value (agents|flows|seats|pack_slots). -1 = unlimited.
     */
    public function entitlement(string $key, int $default = 0): int
    {
        return (int) ($this->entitlements[$key] ?? $default);
    }
}
