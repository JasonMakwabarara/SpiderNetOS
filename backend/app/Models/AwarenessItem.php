<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AwarenessItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'source', 'title', 'detail', 'severity', 'status', 'raised_by', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }
}
