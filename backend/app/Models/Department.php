<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = ['tenant_id', 'name'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
