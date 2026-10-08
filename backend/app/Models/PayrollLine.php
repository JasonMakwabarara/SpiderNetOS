<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayrollLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'payroll_run_id', 'employee_id', 'contract_id', 'minutes', 'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }
}
