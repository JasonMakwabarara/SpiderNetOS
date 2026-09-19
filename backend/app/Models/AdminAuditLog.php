<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    use HasFactory;

    protected $table = 'admin_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'actor_email',
        'action',
        'target_type',
        'target_id',
        'payload',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? now();
        });
    }

    /** Helper factory to record an admin action. */
    public static function record(
        ?string $tenantId,
        string $actorId,
        string $actorEmail,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $payload = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'actor_email' => $actorEmail,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
