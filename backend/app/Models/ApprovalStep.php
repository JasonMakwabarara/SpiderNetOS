<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStep extends Model
{
    use HasUuids;

    protected $fillable = [
        'approval_id', 'tenant_id', 'step_order', 'approver_type',
        'approver_role', 'approver_id', 'delegated_to', 'status',
        'acted_by', 'response', 'expires_at', 'responded_at',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'expires_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Whether the given user may act on this step: the named approver, the
     * delegate, or any user holding the step's role at-or-above rank.
     */
    public function actableBy(User $user): bool
    {
        if ($this->approver_type === 'user') {
            return $user->id === $this->approver_id || $user->id === $this->delegated_to;
        }

        if ($user->id === $this->delegated_to) {
            return true;
        }

        return $this->approver_role !== null && $user->atLeastRole($this->approver_role);
    }
}
