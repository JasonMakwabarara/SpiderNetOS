<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Fan-outs a notification to a user across enabled channels. Push respects the
 * per-user preference; in-app real-time is handled by the app's existing
 * broadcast layer. Called from triggers like ApprovalEngine (approval_pending)
 * and CostGovernor (budget_alert).
 */
class NotificationService
{
    public function __construct(private readonly WebPushService $webPush) {}

    /**
     * @param  array<string,mixed>  $payload  {title, body, url}
     */
    public function notify(User $user, string $eventType, array $payload): void
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
}
