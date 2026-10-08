<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceDay extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = ['tenant_id', 'employee_id', 'work_date', 'minutes', 'punctuality'];

    protected function casts(): array
    {
        return ['work_date' => 'date'];
    }
}
