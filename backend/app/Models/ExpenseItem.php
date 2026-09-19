<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ExpenseItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'expense_report_id', 'tenant_id', 'category_id', 'merchant',
        'description', 'expense_date', 'amount', 'currency', 'has_receipt',
        'policy_flags', 'gl_account_id', 'metadata',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:4',
        'has_receipt' => 'boolean',
        'policy_flags' => 'array',
        'metadata' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ExpenseReport::class, 'expense_report_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function receipts(): MorphMany
    {
        return $this->morphMany(SpendDocument::class, 'attachable');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
