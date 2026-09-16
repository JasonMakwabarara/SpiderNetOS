<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fan-outs a notification to a user across enabled channels. Push respects the
 * per-user preference; in-app real-time is handled by the app's existing
 * broadcast layer. Called from triggers like ApprovalEngine (approval_pending)
 * and CostGovernor (budget_alert).
 *
 * Attention budget (plan D8 #4): every event type has an urgency tier —
 * interrupt goes straight through, bundle is parked by NotificationBundler
 * until the user's bundle_time, silent only logs. Users override the tier,
 * quiet hours, bundle time and timezone in preferences.notifications.
 */
class NotificationService
{
    public const URGENCIES = ['interrupt', 'bundle', 'silent'];

    public function __construct(private readonly WebPushService $webPush) {}

    /**
     * @param array<string,mixed> $payload {title, body, url}
     */
    public function notify(User $user, string $eventType, array $payload): void
    {
        $urgency = $this->urgencyFor($user, $eventType);

        if ($urgency === 'silent') {
            Log::info('notification.silent', ['user_id' => $user->id, 'event_type' => $eventType, 'title' => $payload['title'] ?? null]);

            return;
        }

        if ($urgency === 'bundle' || $this->inQuietHours($user)) {
            app(NotificationBundler::class)->enqueue((string) $user->tenant_id, (string) $user->id, $eventType, $payload);

            return;
        }

        $this->deliver($user, $eventType, $payload);
    }

    /** Straight to the channels — no urgency routing (the bundle summary itself uses this). */
    public function deliver(User $user, string $eventType, array $payload): void
    {
        if (NotificationPreference::isEnabled($user->id, $eventType, 'push')) {
            $this->webPush->sendToUser($user->id, $payload + ['event_type' => $eventType]);
        }
    }

    /** Notify every user in a tenant (e.g. all admins on a pending approval). */
    public function notifyTenantRole(string $tenantId, array $roles, string $eventType, array $payload): void
    {
        User::where('tenant_id', $tenantId)->whereIn('role', $roles)->get()
            ->each(fn (User $u) => $this->notify($u, $eventType, $payload));
    }

    /** interrupt | bundle | silent — the user's override, else the config default for the event type. */
    public function urgencyFor(User $user, string $eventType): string
    {
        $override = self::settingsFor($user)['urgency'][$eventType] ?? null;
        if (is_string($override) && in_array($override, self::URGENCIES, true)) {
            return $override;
        }

        return NotificationPreference::defaultUrgency($eventType);
    }

    /**
     * Quiet hours apply only when the user set them (preferences.notifications.quiet_hours);
     * an interrupt raised inside them is bundled for the morning instead.
     */
    public function inQuietHours(User $user, ?\DateTimeInterface $now = null): bool
    {
        $settings = self::settingsFor($user);
        $quiet = $settings['quiet_hours'];
        if (! is_array($quiet) || empty($quiet['start']) || empty($quiet['end'])) {
            return false;
        }

        $now = $now ? Carbon::instance($now) : now();
        try {
            $local = $now->copy()->setTimezone($settings['timezone']);
        } catch (\Throwable) {
            $local = $now->copy();
        }

        $minutes = $local->hour * 60 + $local->minute;
        $start = self::minutesOf((string) $quiet['start']);
        $end = self::minutesOf((string) $quiet['end']);
        if ($start === null || $end === null || $start === $end) {
            return false;
        }

        // 22:00 → 07:00 wraps midnight.
        return $start < $end
            ? ($minutes >= $start && $minutes < $end)
            : ($minutes >= $start || $minutes < $end);
    }

    /**
     * @return array{timezone: string, quiet_hours: array{start: string, end: string}|null, bundle_time: string, urgency: array<string, string>}
     */
    public static function settingsFor(User $user): array
    {
        $prefs = (array) (((array) ($user->preferences ?? []))['notifications'] ?? []);
        $defaults = (array) config('notifications.defaults', []);

        $quiet = $prefs['quiet_hours'] ?? null;
        if (! is_array($quiet) || empty($quiet['start']) || empty($quiet['end'])) {
            $quiet = null;
        }

        $timezone = is_string($prefs['timezone'] ?? null) && $prefs['timezone'] !== '' ? $prefs['timezone'] : (string) ($defaults['timezone'] ?? 'UTC');
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = 'UTC';
        }

        return [
            'timezone' => $timezone,
            'quiet_hours' => $quiet,
            'bundle_time' => is_string($prefs['bundle_time'] ?? null) && self::minutesOf($prefs['bundle_time']) !== null
                ? $prefs['bundle_time'] : (string) ($defaults['bundle_time'] ?? '08:00'),
            'urgency' => array_filter((array) ($prefs['urgency'] ?? []), 'is_string'),
        ];
    }

    private static function minutesOf(string $hhmm): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return $h * 60 + $min;
    }
}
