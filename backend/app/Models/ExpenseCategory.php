<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'gl_account_id',
        'per_expense_limit', 'monthly_limit', 'requires_receipt_over',
        'active', 'metadata',
    ];

    protected $casts = [
        'per_expense_limit' => 'decimal:4',
        'monthly_limit' => 'decimal:4',
        'requires_receipt_over' => 'decimal:4',
        'active' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class, 'category_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
