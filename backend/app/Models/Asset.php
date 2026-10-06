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

    protected $fillable = ['tenant_id', 'tag', 'name', 'status'];

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }
}
