<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentInstruction extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $fillable = [
        'tenant_id', 'bill_id', 'reimbursement_id', 'rail', 'status',
        'amount', 'currency', 'scheduled_for', 'external_reference',
        'idempotency_key', 'failure_reason', 'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'scheduled_for' => 'date',
        'metadata' => 'array',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(Reimbursement::class);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
