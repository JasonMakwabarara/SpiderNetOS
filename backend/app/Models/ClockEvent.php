<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClockEvent extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'type',
        'recorded_at',
        'source',
        'attendance_site_id',
        'attendance_device_id',
        'external_event_id',
        'sensor_id',
    ];

    protected $casts = ['recorded_at' => 'datetime'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
