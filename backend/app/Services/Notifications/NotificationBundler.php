<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationBundleItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Attention budget (plan D8 #4): bundle-tier notifications are parked here
 * and sent once at the user's bundle_time as a single summary —
 * "4 drafts, 1 question — about 6 minutes". Interrupt-tier events never
 * pass through; NotificationService routes them straight to the channels.
 */
class NotificationBundler
{
    public const SUMMARY_EVENT = 'bundle';

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Park one event. Pending items for the same user, event and url are
     * merged (payload refreshed) so a flapping source never floods the bundle.
     *
     * @param  array<string, mixed>  $payload  {title, body, url, ...}
     */
    public function enqueue(string $tenantId, string $userId, string $eventType, array $payload): NotificationBundleItem
    {
        $url = isset($payload['url']) && is_string($payload['url']) ? $payload['url'] : null;

        if ($url !== null) {
            $existing = NotificationBundleItem::forUser($userId)->pending()
                ->where('tenant_id', $tenantId)->where('event_type', $eventType)
                ->where('payload->url', $url)->first();
            if ($existing !== null) {
                $existing->payload = $payload;
                $existing->save();

                return $existing;
            }
        }

        return NotificationBundleItem::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'payload' => $payload,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function pending(string $tenantId, string $userId): array
    {
        return $this->pendingItems($tenantId, $userId)->map(fn (NotificationBundleItem $i): array => [
            'id' => $i->id,
            'event_type' => $i->event_type,
            'payload' => (array) $i->payload,
            'created_at' => $i->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * Send every pending item for the user as one summary and mark them sent.
     * Returns the summary, or null when nothing was pending.
     *
     * @return array<string, mixed>|null
     */
    public function flush(string $tenantId, string $userId): ?array
    {
        $items = $this->pendingItems($tenantId, $userId);
        if ($items->isEmpty()) {
            return null;
        }

        $user = User::find($userId);
        $bundleId = (string) Str::uuid();
        $now = now();

        if ($user === null) {
            NotificationBundleItem::whereIn('id', $items->pluck('id'))->update(['status' => NotificationBundleItem::STATUS_DROPPED, 'bundle_id' => $bundleId, 'updated_at' => $now]);

            return null;
        }

        $summary = $this->summarise($items);
        $summary['bundle_id'] = $bundleId;
        $summary['user_id'] = $userId;
        $summary['tenant_id'] = $tenantId;

        $this->notifications->deliver($user, self::SUMMARY_EVENT, [
            'title' => 'Needs you: '.$summary['line'],
            'body' => implode("\n", array_map(fn (array $i): string => '• '.$i['title'], array_slice($summary['items'], 0, 6))),
            'url' => '/',
            'bundle_id' => $bundleId,
            'counts' => $summary['counts'],
            'minutes' => $summary['minutes'],
            'items' => $summary['items'],
        ]);

        NotificationBundleItem::whereIn('id', $items->pluck('id'))->update([
            'status' => NotificationBundleItem::STATUS_SENT,
            'bundle_id' => $bundleId,
            'sent_at' => $now,
            'updated_at' => $now,
        ]);

        Log::info('notifications.bundle.flushed', ['user_id' => $userId, 'items' => $items->count(), 'line' => $summary['line']]);

        return $summary;
    }

    /**
     * Flush every user whose local time has passed bundle_time and who has
     * not received a bundle yet today. Returns the number of bundles sent.
     */
    public function flushDue(?\DateTimeInterface $now = null): int
    {
        if (! Schema::hasTable('notification_bundle_items')) {
            return 0;
        }
        $now = $now ? Carbon::instance($now) : now();

        $pairs = NotificationBundleItem::pending()->select(['tenant_id', 'user_id'])->distinct()->get();
        $sent = 0;
        foreach ($pairs as $pair) {
            $user = User::find($pair->user_id);
            if ($user === null || ! $this->dueFor($user, $now)) {
                continue;
            }
            if ($this->flush((string) $pair->tenant_id, (string) $pair->user_id) !== null) {
                $sent++;
            }
        }

        return $sent;
    }

    /** True once the user's local clock has passed bundle_time and no bundle went out today. */
    public function dueFor(User $user, ?\DateTimeInterface $now = null): bool
    {
        $settings = NotificationService::settingsFor($user);
        $now = $now ? Carbon::instance($now) : now();
        try {
            $local = $now->copy()->setTimezone($settings['timezone']);
        } catch (\Throwable) {
            $local = $now->copy();
        }

        [$h, $m] = array_map('intval', explode(':', $settings['bundle_time'].':0'));
        if ($local->hour < $h || ($local->hour === $h && $local->minute < $m)) {
            return false;
        }

        $lastSent = NotificationBundleItem::forUser((string) $user->id)
            ->where('status', NotificationBundleItem::STATUS_SENT)->max('sent_at');
        if ($lastSent === null) {
            return true;
        }
        try {
            $lastLocal = Carbon::parse((string) $lastSent)->setTimezone($settings['timezone']);
        } catch (\Throwable) {
            $lastLocal = Carbon::parse((string) $lastSent);
        }

        return ! $lastLocal->isSameDay($local);
    }

    /**
     * "4 drafts, 1 question — about 6 minutes".
     *
     * @param  iterable<NotificationBundleItem>  $items
     * @return array{line: string, counts: array<string, int>, minutes: int, items: list<array<string, mixed>>}
     */
    public function summarise(iterable $items): array
    {
        $types = (array) config('notifications.event_types', []);
        $fallbackMinutes = (float) config('notifications.minutes_per_item', 1.5);

        $counts = [];
        $minutes = 0.0;
        $list = [];
        foreach ($items as $item) {
            $type = (string) $item->event_type;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $minutes += (float) ($types[$type]['minutes'] ?? $fallbackMinutes);
            $payload = (array) $item->payload;
            $list[] = [
                'id' => $item->id,
                'event_type' => $type,
                'title' => (string) ($payload['title'] ?? ($types[$type]['label'] ?? Str::headline($type))),
                'url' => $payload['url'] ?? null,
            ];
        }

        arsort($counts, SORT_NUMERIC);
        $parts = [];
        foreach ($counts as $type => $n) {
            $noun = $types[$type]['noun'] ?? [Str::of($type)->replace('_', ' ')->toString(), Str::of($type)->replace('_', ' ')->plural()->toString()];
            $parts[] = $n.' '.($n === 1 ? $noun[0] : $noun[1]);
        }
        $total = max(1, (int) ceil($minutes));

        return [
            'line' => implode(', ', $parts).' — about '.$total.' minute'.($total === 1 ? '' : 's'),
            'counts' => $counts,
            'minutes' => $total,
            'items' => $list,
        ];
    }

    /** @return Collection<int, NotificationBundleItem> */
    private function pendingItems(string $tenantId, string $userId): Collection
    {
        if (! Schema::hasTable('notification_bundle_items')) {
            return new Collection;
        }

        return NotificationBundleItem::forUser($userId)->pending()->where('tenant_id', $tenantId)->orderBy('created_at')->get();
    }
}
