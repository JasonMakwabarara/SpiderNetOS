<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThreeWayMatch extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'purchase_order_id',
        'invoice_id',
        'status',
    ];

    /** @return HasMany<ThreeWayMatchLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ThreeWayMatchLine::class);
    }
}
