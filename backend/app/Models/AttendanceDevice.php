<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDevice extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'attendance_site_id',
        'employee_id',
        'external_device_id',
        'status',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(AttendanceSite::class, 'attendance_site_id');
    }
}
