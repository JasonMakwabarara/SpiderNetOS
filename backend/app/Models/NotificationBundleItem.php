<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A bundle-tier notification parked until the user's bundle_time (plan D8
 * #4). NotificationBundler::flush() sends every pending item for a user as
 * one summary and stamps them with the same bundle_id.
 */
class NotificationBundleItem extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DROPPED = 'dropped';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'tenant_id', 'user_id', 'event_type', 'payload', 'status', 'bundle_id', 'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
