<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformInvoiceLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id', 'kind', 'description', 'amount_cents', 'meta',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'meta' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PlatformInvoice::class, 'invoice_id');
    }
}
