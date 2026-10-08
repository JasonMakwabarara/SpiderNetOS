<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id', 'tag', 'name', 'status',
        'acquired_on', 'cost', 'residual_value', 'useful_life_months', 'depreciation_method',
    ];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'cost' => 'decimal:4',
            'residual_value' => 'decimal:4',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }
}
