<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Requisition extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'employee_id',
        'requisition_number',
        'title',
        'status',
    ];

    /** @return HasMany<RequisitionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class);
    }
}
