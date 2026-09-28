<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** GET /api/notifications/vapid-key — public VAPID key for PWA subscription. */
    public function vapidKey(): JsonResponse
    {
        return response()->json(['public_key' => config('webpush.public_key')]);
    }

    /** POST /api/notifications/push/subscribe */
    public function subscribe(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $sub = PushSubscription::updateOrCreate(
            ['user_id' => $user->id, 'endpoint' => $v['endpoint']],
            ['tenant_id' => $user->tenant_id, 'p256dh' => $v['keys']['p256dh'], 'auth' => $v['keys']['auth']],
        );

        return response()->json(['data' => ['id' => $sub->id]], 201);
    }

    /** POST /api/notifications/push/unsubscribe */
    public function unsubscribe(Request $request): JsonResponse
    {
        $v = $request->validate(['endpoint' => 'required|string']);
        PushSubscription::forUser($request->user()->id)->where('endpoint', $v['endpoint'])->delete();

        return response()->json(['data' => ['unsubscribed' => true]]);
    }

    /** GET /api/notifications/preferences */
    public function preferences(Request $request): JsonResponse
    {
        $prefs = NotificationPreference::where('user_id', $request->user()->id)
            ->get(['event_type', 'channel', 'enabled']);

        return response()->json(['data' => $prefs]);
    }

    /** PUT /api/notifications/preferences */
    public function updatePreferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'event_type' => 'required|in:'.implode(',', NotificationPreference::EVENT_TYPES),
            'channel' => 'required|in:push,in_app',
            'enabled' => 'required|boolean',
        ]);

        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'event_type' => $v['event_type'], 'channel' => $v['channel']],
            ['enabled' => $v['enabled']],
        );

        return response()->json(['data' => ['updated' => true]]);
    }
}
