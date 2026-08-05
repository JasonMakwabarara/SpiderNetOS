<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reimbursement extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'expense_report_id', 'reimbursement_number', 'user_id',
        'amount', 'currency', 'status', 'method', 'reference', 'paid_at',
        'notes', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
