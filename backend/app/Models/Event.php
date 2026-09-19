<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'event_log';

    protected $fillable = [
        'id',
        'tenant_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'metadata',
        'version',
        'occurred_at',
        'sequence_num',
        'hash',
        'previous_hash',
    ];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public $timestamps = false;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public function scopeForAggregate($query, string $type, string $id)
    {
        return $query
            ->where('aggregate_type', $type)
            ->where('aggregate_id', $id)
            ->orderBy('version');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeSince($query, string $since)
    {
        return $query->where('occurred_at', '>', $since);
    }

    public function scopeForTenantOrdered($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId)->orderBy('sequence_num');
    }
}
