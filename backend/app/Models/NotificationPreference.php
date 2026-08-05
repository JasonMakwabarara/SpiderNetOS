<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'event_type', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public const EVENT_TYPES = ['approval_pending', 'budget_alert'];
    public const CHANNELS = ['push', 'in_app'];

    /** Is a channel enabled for a user+event? Defaults to enabled when unset. */
    public static function isEnabled(string $userId, string $eventType, string $channel): bool
    {
        $row = self::where('user_id', $userId)
            ->where('event_type', $eventType)
            ->where('channel', $channel)
            ->first();

        return $row?->enabled ?? true;
    }
}
