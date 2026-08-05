<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Requires Postgres. Web-push subscription + preference endpoints. */
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Push Co', 'slug' => 'push-'.Str::lower(Str::random(8)),
            'status' => 'active', 'plan' => 'launch', 'onboarding_completed_at' => now(),
        ]);

        return User::create([
            'name' => 'U', 'email' => 'p@'.Str::lower(Str::random(8)).'.test',
            'password' => bcrypt('pw'), 'tenant_id' => $tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);
    }

    public function test_subscribe_is_idempotent_per_endpoint(): void
    {
        $user = $this->user();
        $payload = [
            'endpoint' => 'https://push.example/abc123',
            'keys' => ['p256dh' => 'k-p256dh', 'auth' => 'k-auth'],
        ];

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/push/subscribe', $payload)->assertCreated();
        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/push/subscribe', $payload)->assertCreated();

        $this->assertSame(1, \App\Models\PushSubscription::forUser($user->id)->count());
    }

    public function test_unsubscribe_removes_the_endpoint(): void
    {
        $user = $this->user();
        $endpoint = 'https://push.example/xyz';
        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/push/subscribe', [
            'endpoint' => $endpoint, 'keys' => ['p256dh' => 'a', 'auth' => 'b'],
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson('/api/notifications/push/unsubscribe', ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(0, \App\Models\PushSubscription::forUser($user->id)->count());
    }

    public function test_preferences_default_enabled_and_can_be_disabled(): void
    {
        $user = $this->user();
        $this->assertTrue(NotificationPreference::isEnabled($user->id, 'approval_pending', 'push'));

        $this->actingAs($user, 'sanctum')->putJson('/api/notifications/preferences', [
            'event_type' => 'approval_pending', 'channel' => 'push', 'enabled' => false,
        ])->assertOk();

        $this->assertFalse(NotificationPreference::isEnabled($user->id, 'approval_pending', 'push'));
    }
}
