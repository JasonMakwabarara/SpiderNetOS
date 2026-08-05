<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessSystem extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    public const FUNCTIONS = [
        'marketing',
        'sales',
        'operations',
        'finance',
        'recruitment',
        'retraining',
        'management',
    ];

    protected $fillable = [
        'tenant_id', 'function', 'name', 'goal',
        'owner_user_id', 'owner_agent_id', 'status',
    ];

    public function processes(): HasMany
    {
        return $this->hasMany(BusinessProcess::class, 'system_id');
    }
}
