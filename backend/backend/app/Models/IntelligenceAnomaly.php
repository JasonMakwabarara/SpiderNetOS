<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceAnomaly extends Model
{
    use HasUuids;

    protected $table = 'intelligence_anomalies';

    protected $fillable = [
        'id',
        'tenant_id',
        'fingerprint',
        'severity',
        'title',
        'description',
        'detected_at',
        'resolved_at',
        'acknowledged_by',
        'source',
        'source_ref',
        'metadata',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
