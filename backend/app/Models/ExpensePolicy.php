<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpensePolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'scope_type', 'scope_value', 'category_id',
        'per_expense_limit', 'daily_limit', 'monthly_limit',
        'receipt_required_over', 'allowed_categories', 'enabled',
    ];

    protected $casts = [
        'per_expense_limit' => 'decimal:4',
        'daily_limit' => 'decimal:4',
        'monthly_limit' => 'decimal:4',
        'receipt_required_over' => 'decimal:4',
        'allowed_categories' => 'array',
        'enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /** True when this policy applies to the given submitter. */
    public function appliesTo(User $user): bool
    {
        return match ($this->scope_type) {
            'tenant' => true,
            'role' => $this->scope_value === $user->role,
            default => false,
        };
    }
}
