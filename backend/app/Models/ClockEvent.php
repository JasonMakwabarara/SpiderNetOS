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

    protected $fillable = ['tenant_id', 'employee_id', 'type', 'recorded_at'];

    protected $casts = ['recorded_at' => 'datetime'];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
