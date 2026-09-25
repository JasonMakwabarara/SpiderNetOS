<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\NotificationBundleItem;
use App\Models\NotificationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\NotificationBundler;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Attention budget (plan D8 #4): urgency tiers, quiet hours, the bundle summary. */
class NotificationBundlerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Bundle Co', 'slug' => 'bundle-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
    }

    private function user(array $notificationPrefs = [], string $role = 'admin'): User
    {
        return User::create([
            'name' => 'U', 'email' => 'u-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => $role, 'onboarding_completed_at' => now(),
            'preferences' => $notificationPrefs === [] ? null : ['notifications' => $notificationPrefs],
        ]);
    }

    public function test_event_types_and_default_urgencies_are_registered(): void
    {
        $this->assertCount(8, NotificationPreference::EVENT_TYPES);
        foreach (NotificationPreference::EVENT_TYPES as $type) {
            $this->assertContains(NotificationPreference::defaultUrgency($type), NotificationService::URGENCIES, $type);
        }
        $this->assertSame('bundle', NotificationPreference::defaultUrgency('artifact_pending'));
        $this->assertSame('interrupt', NotificationPreference::defaultUrgency('approval_pending'));
        $this->assertSame('interrupt', NotificationPreference::defaultUrgency('outreach_digest'), 'legacy types go straight through');
    }

    public function test_bundle_tier_is_parked_and_interrupt_goes_straight_through(): void
    {
        $user = $this->user();
        $this->mock(WebPushService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->once()
                ->withArgs(fn (string $userId, array $payload) => $payload['event_type'] === 'approval_pending')->andReturn(1);
        });

        $service = app(NotificationService::class);
        $service->notify($user, 'artifact_pending', ['title' => 'Draft: Acme step 1', 'url' => '/agents/runs/1']);
        $service->notify($user, 'artifact_pending', ['title' => 'Draft: Acme step 1 (updated)', 'url' => '/agents/runs/1']);
        $service->notify($user, 'run_blocked', ['title' => 'Pricing?', 'url' => '/agents/runs/2']);
        $service->notify($user, 'approval_pending', ['title' => 'Approve the sequence', 'url' => '/approvals']);

        $pending = NotificationBundleItem::forUser((string) $user->id)->pending()->get();
        $this->assertCount(2, $pending, 'same event + url is merged');
        $this->assertSame('Draft: Acme step 1 (updated)', $pending->firstWhere('event_type', 'artifact_pending')->payload['title']);
    }

    public function test_user_overrides_and_silent_tier(): void
    {
        $user = $this->user(['urgency' => ['approval_pending' => 'silent', 'run_blocked' => 'interrupt']]);
        $this->mock(WebPushService::class, function ($mock) {
            $mock->shouldReceive('sendToUser')->once()
                ->withArgs(fn (string $userId, array $payload) => $payload['event_type'] === 'run_blocked')->andReturn(1);
        });
        Log::shouldReceive('info')->withArgs(fn (string $msg) => $msg === 'notification.silent')->once();
        Log::shouldReceive('info')->withAnyArgs()->zeroOrMoreTimes();

        $service = app(NotificationService::class);
        $service->notify($user, 'approval_pending', ['title' => 'silenced']);
        $service->notify($user, 'run_blocked', ['title' => 'interrupts now']);

        $this->assertSame(0, NotificationBundleItem::forUser((string) $user->id)->count());
    }

    public function test_quiet_hours_bundle_interrupts_until_morning(): void
    {
        $user = $this->user(['timezone' => 'Europe/London', 'quiet_hours' => ['start' => '22:00', 'end' => '07:00'], 'bundle_time' => '08:00']);
        $this->mock(WebPushService::class, fn ($mock) => $mock->shouldReceive('sendToUser')->never());
        $service = app(NotificationService::class);

        Carbon::setTestNow(Carbon::parse('2026-09-16 22:30:00', 'UTC')); // 23:30 London
        $this->assertTrue($service->inQuietHours($user));
        $service->notify($user, 'approval_pending', ['title' => 'late night approval']);
        $this->assertSame(1, NotificationBundleItem::forUser((string) $user->id)->pending()->count());

        Carbon::setTestNow(Carbon::parse('2026-09-17 06:30:00', 'UTC')); // 07:30 London — quiet over, bundle not yet due
        $this->assertFalse($service->inQuietHours($user));
        $this->assertFalse(app(NotificationBundler::class)->dueFor($user));

        Carbon::setTestNow(Carbon::parse('2026-09-17 07:05:00', 'UTC')); // 08:05 London
        $this->assertTrue(app(NotificationBundler::class)->dueFor($user));
        Carbon::setTestNow();
    }

    public function test_flush_sends_one_summary_and_marks_items_sent(): void
    {
        $user = $this->user(['bundle_time' => '08:00', 'timezone' => 'UTC']);
        $captured = null;
        $this->mock(WebPushService::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('sendToUser')->once()->withArgs(function (string $userId, array $payload) use (&$captured) {
                $captured = $payload;

                return true;
            })->andReturn(1);
        });

        $bundler = app(NotificationBundler::class);
        foreach (range(1, 4) as $i) {
            $bundler->enqueue((string) $this->tenant->id, (string) $user->id, 'artifact_pending', ['title' => "Draft {$i}", 'url' => "/agents/runs/{$i}"]);
        }
        $bundler->enqueue((string) $this->tenant->id, (string) $user->id, 'run_blocked', ['title' => 'Which price band?', 'url' => '/agents/runs/9']);

        $this->assertNull($bundler->flush((string) $this->tenant->id, (string) Str::uuid()), 'nothing pending for another user');

        Carbon::setTestNow(Carbon::parse('2026-09-16 08:30:00', 'UTC'));
        $this->assertSame(1, $bundler->flushDue());
        Carbon::setTestNow();

        $this->assertNotNull($captured);
        $this->assertSame('bundle', $captured['event_type']);
        $this->assertSame('Needs you: 4 drafts, 1 question — about 7 minutes', $captured['title']);
        $this->assertSame(['artifact_pending' => 4, 'run_blocked' => 1], $captured['counts']);
        $this->assertCount(5, $captured['items']);

        $items = NotificationBundleItem::forUser((string) $user->id)->get();
        $this->assertCount(5, $items);
        $this->assertTrue($items->every(fn (NotificationBundleItem $i) => $i->status === 'sent' && $i->bundle_id === $items->first()->bundle_id && $i->sent_at !== null));
        $this->assertNull($bundler->flush((string) $this->tenant->id, (string) $user->id), 'second flush has nothing to send');

        // Same day: not due again even with new items.
        $bundler->enqueue((string) $this->tenant->id, (string) $user->id, 'decision_due', ['title' => 'Renew?']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 12:00:00', 'UTC'));
        $this->assertSame(0, $bundler->flushDue());
        Carbon::setTestNow();
    }

    public function test_summary_line_wording(): void
    {
        $bundler = app(NotificationBundler::class);
        $items = collect([
            new NotificationBundleItem(['event_type' => 'artifact_pending', 'payload' => ['title' => 'a']]),
            new NotificationBundleItem(['event_type' => 'decision_due', 'payload' => ['title' => 'b']]),
            new NotificationBundleItem(['event_type' => 'decision_due', 'payload' => ['title' => 'c']]),
        ]);

        $summary = $bundler->summarise($items);

        $this->assertSame('2 decisions, 1 draft — about 6 minutes', $summary['line']);
        $this->assertSame(6, $summary['minutes']);
    }

    public function test_preferences_endpoint_accepts_the_new_event_types(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')->putJson('/api/notifications/preferences', [
            'event_type' => 'breaker_tripped', 'channel' => 'push', 'enabled' => false,
        ])->assertOk();

        $this->assertFalse(NotificationPreference::isEnabled((string) $user->id, 'breaker_tripped', 'push'));
    }
}
