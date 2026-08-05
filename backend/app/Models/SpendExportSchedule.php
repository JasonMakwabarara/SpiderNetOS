<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpendExportSchedule extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'export_type', 'scope', 'frequency', 'delivery',
        'destination', 'last_run_at', 'enabled',
    ];

    protected $casts = [
        'destination' => 'array',
        'last_run_at' => 'datetime',
        'enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * A weekly schedule is due on its first run of the ISO week; a monthly
     * schedule on its first run of the calendar month. The daily runner
     * therefore fires weekly schedules Monday 04:00 and monthly ones on the
     * 1st at 04:00.
     */
    public function isDue(\Illuminate\Support\Carbon $now): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if ($this->last_run_at === null) {
            return true;
        }

        return match ($this->frequency) {
            'weekly' => $this->last_run_at->lt($now->copy()->startOfWeek()),
            'monthly' => $this->last_run_at->lt($now->copy()->startOfMonth()),
            default => false,
        };
    }
}
