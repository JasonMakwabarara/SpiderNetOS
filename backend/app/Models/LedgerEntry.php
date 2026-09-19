<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'account_id', 'chart_account_id', 'transaction_id',
        'entry_type', 'side', 'amount', 'currency', 'reference_type',
        'reference_id', 'description', 'event_log_id', 'posted_at',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'posted_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_account_id');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForTransaction($query, string $transactionId)
    {
        return $query->where('transaction_id', $transactionId);
    }

    public function scopeForDateRange($query, string $start, string $end)
    {
        return $query->whereBetween('posted_at', [$start, $end]);
    }

    public function scopeDebits($query)
    {
        return $query->where('side', 'debit');
    }

    public function scopeCredits($query)
    {
        return $query->where('side', 'credit');
    }
}
