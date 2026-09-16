<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'event_type', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    /**
     * Event types with a default urgency tier in config/notifications.php
     * (plan D8 #4): interrupt | bundle | silent.
     */
    public const EVENT_TYPES = [
        'approval_pending',
        'budget_alert',
        'brief_ready',
        'run_blocked',
        'artifact_pending',
        'decision_due',
        'delegation_expired',
        'breaker_tripped',
    ];

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

    /** Config default urgency for an event type; unknown/legacy types go straight through. */
    public static function defaultUrgency(string $eventType): string
    {
        $urgency = config("notifications.event_types.{$eventType}.urgency");
        if (is_string($urgency) && in_array($urgency, ['interrupt', 'bundle', 'silent'], true)) {
            return $urgency;
        }

        $fallback = (string) config('notifications.default_urgency', 'interrupt');

        return in_array($fallback, ['interrupt', 'bundle', 'silent'], true) ? $fallback : 'interrupt';
    }
}
