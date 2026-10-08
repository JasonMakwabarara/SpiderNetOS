<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cashbook extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'name',
        'currency',
        'status',
    ];

    /** @return HasMany<CashMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }
}
