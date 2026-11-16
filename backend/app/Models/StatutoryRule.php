<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StatutoryRule extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'name',
        'currency',
        'effective_from',
        'definition',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'definition' => 'array',
    ];
}
