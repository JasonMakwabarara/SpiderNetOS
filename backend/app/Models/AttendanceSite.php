<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AttendanceSite extends Model
{
    use HasUuids, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'name',
        'secret_hash',
        'last_synced_at',
        'sync_cursor',
    ];

    protected $hidden = ['secret_hash'];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];
}
