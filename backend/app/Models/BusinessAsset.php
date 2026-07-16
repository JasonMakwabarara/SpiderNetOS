<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BusinessAsset extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'type', 'name', 'ref_type', 'ref_id', 'version',
        'quarter', 'status', 'created_by',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
