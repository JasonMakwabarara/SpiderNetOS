<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAssignment extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = ['tenant_id', 'asset_id', 'employee_id', 'assigned_at', 'returned_at'];

    protected $casts = [
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
