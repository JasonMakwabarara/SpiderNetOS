<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DepreciationEntry extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = ['tenant_id', 'asset_id', 'period', 'amount', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
