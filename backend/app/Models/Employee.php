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
        'position_id',
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
    ];

    protected $casts = [
        'start_date' => 'date',
        'inactive_from' => 'date',
    ];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Position, $this> */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
