<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmploymentContract extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id', 'employee_id', 'position_id', 'contract_number',
        'starts_on', 'ends_on', 'pay_amount', 'currency', 'pay_period', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'pay_amount' => 'decimal:4',
        ];
    }
}
