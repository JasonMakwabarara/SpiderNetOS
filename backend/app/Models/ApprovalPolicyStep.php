<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalPolicyStep extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'approval_policy_id', 'step_order', 'approver_type',
        'approver_role', 'approver_id', 'expires_after_hours',
        'escalate_to_role',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'expires_after_hours' => 'integer',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ApprovalPolicy::class, 'approval_policy_id');
    }
}
