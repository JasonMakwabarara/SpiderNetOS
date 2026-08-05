<?php

declare(strict_types=1);

namespace Tests\Feature\Spend;

use App\Jobs\WeeklySpendDigestJob;
use App\Models\ExpenseCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Spend\Accounting\SpendDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpendDigestTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Digest Tenant',
            'slug' => 'digest-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    private function createUser(Tenant $tenant, string $role = 'member'): User
    {
        return User::create([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.Str::lower(Str::random(8)).'@test.test',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'role' => $role,
            'onboarding_completed_at' => now(),
        ]);
    }

    /** Approved report: 460 travel + 90 meals inside the digest window. */
    private function seedSpend(Tenant $tenant, User $user): void
    {
        $travel = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Travel', 'slug' => 'travel',
        ]);
        $meals = ExpenseCategory::create([
            'tenant_id' => $tenant->id, 'name' => 'Meals', 'slug' => 'meals',
        ]);

        $report = $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses', [
                'title' => 'Digest week',
                'items' => [
                    ['description' => 'Flight', 'amount' => '400.00', 'expense_date' => now()->subDays(2)->toDateString(), 'category_id' => $travel->id, 'merchant' => 'AcmeAir'],
                    ['description' => 'Taxi', 'amount' => '60.00', 'expense_date' => now()->subDays(2)->toDateString(), 'category_id' => $travel->id, 'merchant' => 'CityCab'],
                    ['description' => 'Dinner', 'amount' => '90.00', 'expense_date' => now()->subDays(1)->toDateString(), 'category_id' => $meals->id, 'merchant' => 'Bistro 9'],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/financial/expenses/'.$report['id'].'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_digest_notification_contains_category_totals(): void
    {
        config(['spend.weekly_digest_enabled' => true, 'spend.digest_llm_insight' => false]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->createUser($tenant, 'admin');
        $this->seedSpend($tenant, $user);

        $captured = [];
        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldReceive('notifyTenantRole')
            ->atLeast()->once()
            ->withArgs(function ($tenantId, $roles, $eventType, $payload) use (&$captured, $tenant) {
                if ($tenantId === $tenant->id && $eventType === 'spend_digest') {
                    $captured[] = $payload;
                }

                return true;
            });

        (new WeeklySpendDigestJob)->handle(app(SpendDigestService::class), $mock);

        $this->assertCount(1, $captured, 'exactly one spend_digest notification expected');
        $payload = $captured[0];

        $this->assertSame('Weekly spend digest', $payload['title']);

        $byCategory = collect($payload['digest']['by_category'])->keyBy('category');
        $this->assertSame('460.0000', $byCategory['Travel']['total']);
        $this->assertSame('90.0000', $byCategory['Meals']['total']);
        $this->assertSame('550.0000', $payload['digest']['total']);

        // Body carries the human-readable category totals.
        $this->assertStringContainsString('Travel', $payload['body']);
        $this->assertStringContainsString('460.00', $payload['body']);

        // Top merchants are ranked by amount.
        $this->assertSame('AcmeAir', $payload['digest']['top_merchants'][0]['merchant']);

        $this->assertArrayHasKey('pending_approvals', $payload['digest']);
        $this->assertArrayHasKey('policy_flag_count', $payload['digest']);
    }

    public function test_digest_is_deterministic_without_llm(): void
    {
        config(['spend.weekly_digest_enabled' => true, 'spend.digest_llm_insight' => false]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->seedSpend($tenant, $user);

        $service = app(SpendDigestService::class);
        $start = now()->subDays(7)->startOfDay();
        $end = now()->endOfDay();

        $first = $service->buildDigest($tenant->id, $start, $end);
        $second = $service->buildDigest($tenant->id, $start, $end);

        $this->assertSame($first, $second, 'digest must be fully deterministic');

        // The insight is the plain-stats sentence — no LLM involved.
        $this->assertSame(
            'Travel was the largest category at USD 460.00 (83.6% of USD 550.00 total spend).',
            $first['insight'],
        );
    }

    public function test_digest_respects_disabled_config_gate(): void
    {
        config(['spend.weekly_digest_enabled' => false]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->createUser($tenant, 'admin');
        $this->seedSpend($tenant, $user);

        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldNotReceive('notifyTenantRole');

        (new WeeklySpendDigestJob)->handle(app(SpendDigestService::class), $mock);

        $this->addToAssertionCount(1); // Mockery expectation verified on close.
    }

    public function test_quiet_tenants_are_skipped(): void
    {
        config(['spend.weekly_digest_enabled' => true]);

        $tenant = $this->createTenant();
        $this->createUser($tenant, 'admin');

        $mock = \Mockery::mock(NotificationService::class);
        $mock->shouldNotReceive('notifyTenantRole');

        (new WeeklySpendDigestJob)->handle(app(SpendDigestService::class), $mock);

        $this->addToAssertionCount(1);
    }
}
