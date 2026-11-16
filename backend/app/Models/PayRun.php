<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayRun extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'period_start',
        'period_end',
        'currency',
        'status',
        'approval_id',
        'statutory_rule_id',
        'summary',
        'journal_transaction_id',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'summary' => 'array',
    ];

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
