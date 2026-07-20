<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A data-subject access (export) or erasure request, per GDPR Art. 15 / 17.
 */
class DsarRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'type', 'subject_type', 'subject_id', 'subject_email',
        'status', 'requested_by', 'artifact_path', 'notes', 'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
