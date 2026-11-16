<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'pay_run_id',
        'employee_id',
        'payable_minutes',
        'overtime_minutes',
        'unpaid_leave_days',
        'gross',
        'net',
        'lines',
    ];

    protected $casts = [
        'lines' => 'array',
        'gross' => 'decimal:2',
        'net' => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }
}
