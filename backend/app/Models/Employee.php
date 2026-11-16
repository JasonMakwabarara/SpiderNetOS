<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'department_id',
        'employee_number',
        'name',
        'first_name',
        'surname',
        'position_title',
        'job_description',
        'start_date',
        'status',
        'inactive_from',
        'inactive_reason',
        'employment_type',
        'pay_basis',
        'pay_rate',
        'pay_currency',
        'national_id',
        'nssa_number',
        'reports_to',
        'user_id',
        'jetson_person_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'inactive_from' => 'date',
        'pay_rate' => 'decimal:2',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to');
    }
}
