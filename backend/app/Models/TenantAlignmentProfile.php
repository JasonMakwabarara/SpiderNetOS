<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantAlignmentProfile extends Model
{
    protected $primaryKey = 'tenant_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'tenant_id', 'origin_story', 'mission', 'vision', 'values',
        'three_year_targets', 'one_year_targets', 'ninety_day_targets',
        'current_cycle_started_at',
    ];

    protected $casts = [
        'values' => 'array',
        'three_year_targets' => 'array',
        'one_year_targets' => 'array',
        'ninety_day_targets' => 'array',
        'current_cycle_started_at' => 'datetime',
    ];
}
