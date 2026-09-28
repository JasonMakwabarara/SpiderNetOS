<?php

declare(strict_types=1);

namespace Tests\Feature\Launch;

use App\Models\BusinessLaunch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Launch\BusinessLaunchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared scaffolding for the business-launch feature tests (plan D7 §5):
 * a tenant with an owner, the `launch.*` flags, and helpers that drive the
 * interview through the real HTTP API the cockpit uses.
 */
abstract class LaunchTestCase extends TestCase
{
    protected Tenant $tenant;

    protected User $owner;

    /** Answers that satisfy every required variable in stages.yaml. */
    protected const REQUIRED_ANSWERS = [
        'business_name' => 'Tidy Tuesdays',
        'one_liner' => 'Weekly office cleaning for small design studios in Leeds.',
        'founder_why' => 'I cleaned offices for ten years and watched studios put up with rotas nobody kept.',
        'mission' => 'Leave every studio spotless before the team arrives.',
        'vision' => 'Twenty cleaners working across Yorkshire on the same weekly promise.',
        'ninety_day_target' => 'Ten paying studios on a weekly contract.',
        'core_offer' => 'A two-hour weekly clean, same cleaner every week.',
        'pricing_model' => 'GBP 100 per visit, billed monthly.',
        'ideal_customer' => 'Design studios of five to fifteen people in central Leeds.',
        'preferred_tone' => 'Warm and straight-talking, never salesy.',
        'competitors' => 'Big contract cleaners charging about GBP 140 a visit.',
        'market_geography' => 'Leeds first, then Yorkshire.',
        'starting_cash' => 'About 10,000',
        'setup_costs' => '2,000 for kit and the website',
        'price_per_unit' => '100 a visit',
        'units_month_one' => '20 visits',
        'monthly_growth' => 'around 10%',
        'cost_of_sale_pct' => 'roughly 40%',
        'fixed_monthly_costs' => '1,500 a month',
        'jurisdiction' => 'uk',
        'launch_channel' => 'LinkedIn and the Leeds creative meetups.',
        'first_ten_customers' => 'The studios on my own street, one door at a time.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Tidy Tuesdays',
            'slug' => 'launch-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'automation_level' => 'assisted',
            'onboarding_completed_at' => now(),
            'settings' => [],
        ]);

        $this->owner = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Thandi Founder',
            'email' => Str::lower(Str::random(10)).'@launch.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'onboarding_completed_at' => now(),
        ]);

        $this->flags(['launch.enabled' => 'on', 'brain.enabled' => 'on']);
    }

    /** @param array<string, string> $values */
    protected function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    protected function actingAsOwner(): static
    {
        $this->actingAs($this->owner, 'sanctum');

        return $this;
    }

    /** POST /api/launch/start */
    protected function startLaunch(?string $jurisdiction = 'uk'): array
    {
        return $this->actingAsOwner()
            ->postJson('/api/launch/start', array_filter(['jurisdiction' => $jurisdiction]))
            ->assertStatus(201)
            ->json('data');
    }

    /** POST /api/launch/answer for one question id. */
    protected function answer(string $questionId, string $answer): array
    {
        return $this->actingAsOwner()
            ->postJson('/api/launch/answer', ['question_id' => $questionId, 'answer' => $answer])
            ->assertOk()
            ->json('data');
    }

    protected function service(): BusinessLaunchService
    {
        return app(BusinessLaunchService::class);
    }

    protected function launchModel(): BusinessLaunch
    {
        return BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
    }

    /**
     * Answer every question the runner asks, using REQUIRED_ANSWERS where a
     * canned answer exists and skipping the optional ones, until the
     * interview has nothing left to ask (or we run out of patience).
     *
     * Driven through the service rather than the HTTP API: this is ~40 turns
     * and each request would re-resolve the feature flag (a Redis connection
     * refusal costs seconds on Windows). The HTTP contract itself is covered
     * by LaunchInterviewTest.
     */
    protected function answerWholeInterview(int $limit = 80): array
    {
        $service = $this->service();
        $launch = $this->launchModel();
        $state = $service->state($launch);

        for ($i = 0; $i < $limit; $i++) {
            $next = $state['next_question'] ?? null;
            if ($next === null) {
                break;
            }
            $id = (string) $next['id'];
            $state = $service->answer($launch, self::REQUIRED_ANSWERS[$id] ?? '', $id);
        }

        return $state;
    }
}
