<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WorkspaceAutonomySetting extends Model
{
    protected $table = 'workspace_autonomy_settings';
    protected $primaryKey = 'workspace_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id', 'autonomy_level', 'auto_execute_threshold', 'rollback_threshold'
    ];
    protected $casts = [
        'auto_execute_threshold' => 'decimal:2',
        'rollback_threshold' => 'decimal:2'
    ];
}
