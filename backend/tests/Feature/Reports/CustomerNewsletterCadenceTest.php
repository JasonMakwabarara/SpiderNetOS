<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\NewsletterIssue;
use App\Models\Tenant;
use App\Services\Reports\CustomerNewsletterCadence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The 12-day customer-newsletter cadence (plan D8 #16): the grid, quiet-day
 * shifting that does not drift, and catch-up after a pause.
 */
class CustomerNewsletterCadenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CustomerNewsletterCadence $cadence;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Cadence Co', 'slug' => 'cadence-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $this->cadence = app(CustomerNewsletterCadence::class);
    }

    private function issue(string $period): NewsletterIssue
    {
        return NewsletterIssue::create([
            'tenant_id' => $this->tenant->id,
            'kind' => NewsletterIssue::KIND_CUSTOMER,
            'period' => $period,
            'status' => NewsletterIssue::STATUS_SENT,
            'sent_at' => Carbon::parse($period),
        ]);
    }

    private function tenantId(): string
    {
        return (string) $this->tenant->id;
    }

    public function test_the_first_issue_does_not_wait_twelve_days(): void
    {
        $today = Carbon::parse('2026-09-16'); // a Wednesday

        $this->assertTrue($this->cadence->isDue($this->tenantId(), $today));
        $this->assertSame('2026-09-16', $this->cadence->nextSlot($this->tenantId(), $today)->toDateString());
        // With nothing sent, the first issue covers the preceding 12 days.
        $this->assertSame('2026-09-04', $this->cadence->coversSince($this->tenantId(), $today)->toDateString());
    }

    public function test_the_next_slot_is_twelve_days_after_the_last_issue(): void
    {
        $this->issue('2026-09-02'); // Wednesday

        $slot = $this->cadence->nextSlot($this->tenantId(), Carbon::parse('2026-09-03'));

        $this->assertSame('2026-09-14', $slot->toDateString()); // Monday
        $this->assertFalse($this->cadence->isDue($this->tenantId(), Carbon::parse('2026-09-13')));
        $this->assertTrue($this->cadence->isDue($this->tenantId(), Carbon::parse('2026-09-14')));
        $this->assertSame('2026-09-02', $this->cadence->coversSince($this->tenantId(), $slot)->toDateString());
    }

    public function test_a_slot_on_a_weekend_shifts_forward_without_moving_the_grid(): void
    {
        $this->issue('2026-09-07'); // Monday; +12 = Saturday 2026-09-19

        $slot = $this->cadence->nextSlot($this->tenantId(), Carbon::parse('2026-09-08'));
        $this->assertSame('2026-09-21', $slot->toDateString()); // Monday
        $this->assertSame(Carbon::MONDAY, $slot->dayOfWeek);

        // The next four slots stay on the 12-day grid from the anchor, not from
        // the shifted date: 09-19, 10-01, 10-13, 10-25 -> weekends shifted.
        $this->assertSame(['2026-09-21', '2026-10-01', '2026-10-13', '2026-10-26'],
            $this->cadence->upcoming($this->tenantId(), Carbon::parse('2026-09-08'), 4));
    }

    public function test_the_cadence_walks_through_the_week_rather_than_fixing_a_weekday(): void
    {
        $this->issue('2026-09-01'); // Tuesday

        $days = array_map(
            fn (string $date): int => Carbon::parse($date)->dayOfWeek,
            $this->cadence->upcoming($this->tenantId(), Carbon::parse('2026-09-02'), 6),
        );

        // The point of 12 days rather than a fortnight: more than one weekday.
        $this->assertGreaterThan(1, count(array_unique($days)));
        $this->assertSame([], array_intersect($days, CustomerNewsletterCadence::QUIET_DAYS));
    }

    public function test_a_long_pause_catches_up_instead_of_firing_a_backlog(): void
    {
        $this->issue('2026-01-05'); // the flag was off for eight months

        $now = Carbon::parse('2026-09-16');
        $slot = $this->cadence->nextSlot($this->tenantId(), $now);

        $this->assertTrue($slot->greaterThanOrEqualTo($now->copy()->startOfDay()));
        $this->assertTrue($slot->lessThan($now->copy()->addDays(CustomerNewsletterCadence::INTERVAL_DAYS + 2)));
        // Still on the original 12-day grid: whole multiples from the anchor.
        $this->assertSame(0, Carbon::parse('2026-01-05')->diffInDays($this->cadence->anchor($this->tenantId(), $now)) % CustomerNewsletterCadence::INTERVAL_DAYS);
    }

    public function test_one_tenants_issues_do_not_move_anothers_cadence(): void
    {
        $this->issue('2026-09-07');

        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other Co', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);

        $this->assertTrue($this->cadence->isDue((string) $other->id, Carbon::parse('2026-09-16')));
        $this->assertFalse($this->cadence->isDue($this->tenantId(), Carbon::parse('2026-09-16')));
    }
}
