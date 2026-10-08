<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayablesPosting extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'invoice_id',
        'transaction_id',
    ];
}
