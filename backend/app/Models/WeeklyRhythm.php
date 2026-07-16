<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WeeklyRhythm extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'week_start', 'priorities', 'checkin',
    ];

    protected $casts = [
        'week_start' => 'date',
        'priorities' => 'array',
        'checkin' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
