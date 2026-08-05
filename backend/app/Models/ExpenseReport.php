<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExpenseReport extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'user_id', 'report_number', 'title', 'description',
        'status', 'currency', 'total_amount', 'policy_violation_count',
        'approval_id', 'rejection_reason', 'submitted_at', 'approved_at',
        'rejected_at', 'reimbursed_at', 'metadata',
    ];

    protected $casts = [
        'total_amount' => 'decimal:4',
        'policy_violation_count' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'reimbursed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExpenseItem::class);
    }

    public function reimbursement(): HasOne
    {
        return $this->hasOne(Reimbursement::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
