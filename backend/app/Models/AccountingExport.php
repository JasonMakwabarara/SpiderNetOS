<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingExport extends Model
{
    use HasUuids;

    public const TYPES = ['quickbooks_csv', 'xero_csv', 'generic_csv'];

    protected $fillable = [
        'tenant_id', 'export_type', 'period_start', 'period_end', 'status',
        'file_path', 'row_count', 'requested_by', 'error',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'row_count' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isGenerated(): bool
    {
        return $this->status === 'generated';
    }
}
