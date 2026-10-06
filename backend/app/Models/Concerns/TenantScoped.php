<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait TenantScoped
{
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where($this->getTable().'.tenant_id', $tenantId);
    }
}
