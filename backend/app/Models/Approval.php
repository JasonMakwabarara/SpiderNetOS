<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/*
| Eloquent projection over the approvals table. ApprovalController has
| imported this class since its creation, but the model never existed —
| writes went through raw DB queries. Status vocabulary:
| pending | approved | rejected | expired.
*/
class Approval extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'requester_id', 'approver_id', 'approval_type',
        'resource_type', 'resource_id', 'reason', 'context', 'status',
        'response', 'requested_at', 'responded_at', 'expires_at',
        'policy_id', 'current_step',
    ];

    protected $casts = [
        'context' => 'array',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'expires_at' => 'datetime',
        'current_step' => 'integer',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('step_order');
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
     * Null for legacy single-stage approvals; set when the approval was
     * created from a multi-step policy chain.
     */
    public function isChained(): bool
    {
        return $this->current_step !== null;
    }
}
