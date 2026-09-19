<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends encrypted web-push via the minishlink/web-push library when it and
 * VAPID keys are present. Safe no-op otherwise (the library + VAPID keys are an
 * owner-provisioned activation step; in-app real-time still works via broadcast).
 *
 * Activate: `composer require minishlink/web-push` + set VAPID_PUBLIC_KEY /
 * VAPID_PRIVATE_KEY.
 */
class WebPushService
{
    public function configured(): bool
    {
        return (bool) config('webpush.public_key')
            && (bool) config('webpush.private_key')
            && class_exists(WebPush::class);
    }

    /**
     * @param  array<string,mixed>  $payload  {title, body, url, ...}
     * @return int subscriptions dispatched to
     */
    public function sendToUser(string $userId, array $payload): int
    {
        if (! $this->configured()) {
            Log::info('webpush.skipped_not_configured', ['user_id' => $userId]);

            return 0;
        }

        $subs = PushSubscription::forUser($userId)->get();
        if ($subs->isEmpty()) {
            return 0;
        }

        $webPushClass = WebPush::class;
        $subClass = Subscription::class;

        $webPush = new $webPushClass(['VAPID' => [
            'subject' => config('webpush.subject'),
            'publicKey' => config('webpush.public_key'),
            'privateKey' => config('webpush.private_key'),
        ]]);

        $count = 0;
        foreach ($subs as $sub) {
            $webPush->queueNotification(
                $subClass::create(['endpoint' => $sub->endpoint, 'keys' => ['p256dh' => $sub->p256dh, 'auth' => $sub->auth]]),
                json_encode($payload),
            );
            $count++;
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess() && method_exists($report, 'isSubscriptionExpired') && $report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }

        return $count;
    }
}
