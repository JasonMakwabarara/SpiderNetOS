<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\MondayLetterJob;
use App\Models\NewsletterIssue;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Content\QuoteBank;
use App\Services\Notifications\NotificationService;
use App\Services\Reports\MondayLetterComposer;
use App\Services\Reports\TrustLedger;
use App\Services\Reports\WeeklyNumbers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Monday letter (plan D8 #11) and the C-Suite newsletter inside it (#15):
 * the seven numbers with their honest gaps, the trust table, locking antlers,
 * one experiment, the quote, and the API.
 */
class MondayLetterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    /** The Monday of the week the letter reports. */
    private Carbon $weekStart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Letter Co', 'slug' => 'letter-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
            'settings' => ['timezone' => 'Africa/Harare'],
        ]);
        $this->user = User::create([
            'name' => 'Jason', 'email' => 'j-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->weekStart = Carbon::now('Africa/Harare')->startOfWeek()->subWeek();
    }

    /** Set the newsletter flag the way the rest of the suite does: config + a cache flush. */
    private function flag(string $value): void
    {
        config()->set('features', array_merge((array) config('features'), ['newsletter.csuite' => $value]));
        Cache::flush();
    }

    private function compose(): array
    {
        return app(MondayLetterComposer::class)->compose((string) $this->tenant->id);
    }

    private function agentRun(string $skill, string $status, ?Carbon $at = null, float $cost = 0.01): string
    {
        $id = (string) Str::uuid();
        $at ??= $this->weekStart->copy()->addDay();
        DB::table('agent_runs')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'agent_id' => null, 'skill_slug' => $skill,
            'mode' => 'single_shot', 'trigger_type' => 'manual', 'status' => $status,
            'inputs' => '{}', 'outputs' => '{}', 'state' => '{}', 'questions' => '[]',
            'tokens' => 100, 'cost_usd' => $cost, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    private function artifact(string $runId, string $status, ?Carbon $at = null): string
    {
        $id = (string) Str::uuid();
        $at ??= $this->weekStart->copy()->addDay();
        DB::table('agent_artifacts')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'run_id' => $runId, 'skill_slug' => 'cold-email-drafting',
            'kind' => 'draft_email', 'status' => $status, 'title' => 'Draft', 'content' => 'Hello.', 'meta' => '{}',
            'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    // ------------------------------------------------------------------ //

    public function test_a_number_that_cannot_be_computed_says_so_instead_of_reporting_zero(): void
    {
        $letter = $this->compose();

        $this->assertSame(array_values(WeeklyNumbers::KEYS), array_keys($letter['numbers']));

        // Nothing has been set up, so the unit economics are withheld, not faked.
        $cac = $letter['numbers']['cac_ltv'];
        $this->assertFalse($cac['available']);
        $this->assertNull($cac['value']);
        $this->assertStringContainsString('held back until there are '.WeeklyNumbers::MIN_DEALS_FOR_UNIT_ECONOMICS, $cac['detail']);
        $this->assertStringContainsString('**CAC / LTV** — not available', $letter['markdown']);

        // An empty wallet is a real zero and is reported as one.
        $this->assertTrue($letter['numbers']['cash_runway']['available']);
        $this->assertSame(0.0, $letter['numbers']['cash_runway']['value']);
    }

    public function test_the_letter_reports_the_week_that_just_ended_in_the_tenants_own_timezone(): void
    {
        $letter = $this->compose();

        $this->assertSame('Africa/Harare', $letter['timezone']);
        $this->assertSame($this->weekStart->toDateString(), $letter['week_start']);
        $this->assertSame($this->weekStart->format('o-\WW'), $letter['period']);
        // A full seven days, ending the day before this week began.
        $this->assertSame($this->weekStart->copy()->addDays(6)->toDateString(), $letter['week_end']);
    }

    public function test_trust_counts_clean_edited_and_rejected_drafts_per_skill(): void
    {
        $runA = $this->agentRun('cold-email-drafting', 'succeeded');
        $runB = $this->agentRun('cold-email-drafting', 'succeeded');
        $kept = $this->artifact($runA, 'approved');
        $edited = $this->artifact($runA, 'applied');
        $this->artifact($runB, 'rejected');

        DB::table('artifact_revisions')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'subject_type' => 'agent_artifact',
            'subject_id' => $edited, 'user_id' => (string) $this->user->id, 'action' => 'edit',
            'skill_slug' => 'cold-email-drafting', 'original_body' => 'Hello.', 'edited_body' => 'Hi there, quite different.',
            'distance' => 0.7, 'categories' => json_encode(['tone']), 'meta' => '{}',
            'created_at' => $this->weekStart->copy()->addDay(), 'updated_at' => $this->weekStart->copy()->addDay(),
        ]);

        $letter = $this->compose();
        $rows = $letter['trust']['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('cold-email-drafting', $rows[0]['skill_slug']);
        $this->assertSame([2, 3, 1, 1, 1], [$rows[0]['runs'], $rows[0]['drafts'], $rows[0]['clean'], $rows[0]['edited'], $rows[0]['rejected']]);
        $this->assertSame([33, 33, 33], [(int) $rows[0]['clean_pct'], (int) $rows[0]['edited_pct'], (int) $rows[0]['rejected_pct']]);
        $this->assertStringContainsString('| cold-email-drafting | 2 | 3 |', $letter['markdown']);

        // The kept draft is untouched, so it counts as clean.
        $this->assertNotSame($kept, $edited);
    }

    public function test_runs_outside_the_reported_week_are_not_counted(): void
    {
        $this->agentRun('cold-email-drafting', 'succeeded', $this->weekStart->copy()->subDay());
        $this->agentRun('cold-email-drafting', 'succeeded', $this->weekStart->copy()->addWeek()->addHour());

        $letter = $this->compose();

        $this->assertSame([], $letter['trust']['rows']);
        $this->assertSame(0, $letter['numbers']['agent_cost']['extra']['runs']);
    }

    public function test_agent_cost_carries_a_real_week_on_week_delta(): void
    {
        $this->agentRun('cold-email-drafting', 'succeeded', $this->weekStart->copy()->addDay(), 0.40);
        $this->agentRun('cold-email-drafting', 'succeeded', $this->weekStart->copy()->subDays(3), 0.10);

        $number = $this->compose()['numbers']['agent_cost'];

        $this->assertEqualsWithDelta(0.40, (float) $number['value'], 0.0001);
        $this->assertSame('up', $number['delta']['direction']);
        $this->assertEqualsWithDelta(300.0, (float) $number['delta']['pct'], 0.1);
    }

    public function test_locking_antlers_names_the_skill_you_keep_rejecting(): void
    {
        $run = $this->agentRun('cold-email-drafting', 'succeeded');
        foreach (range(1, 3) as $_) {
            $this->artifact($run, 'rejected');
        }
        $this->artifact($run, 'approved');

        $letter = $this->compose();

        $this->assertNotNull($letter['antlers']);
        $this->assertStringContainsString('rejecting more of cold-email-drafting', $letter['antlers']['title']);
        $this->assertSame('/skills/cold-email-drafting', $letter['antlers']['action']['path']);
        $this->assertStringContainsString('## Locking antlers', $letter['markdown']);
    }

    public function test_a_quiet_week_argues_about_nothing(): void
    {
        $letter = $this->compose();

        $this->assertNull($letter['antlers']);
        $this->assertStringNotContainsString('## Locking antlers', $letter['markdown']);
    }

    public function test_a_promotion_is_proposed_with_its_evidence_and_never_applied(): void
    {
        $this->agentRun('cold-email-drafting', 'succeeded');
        DB::table('tenant_skills')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'skill_slug' => 'cold-email-drafting',
            'enabled' => true, 'autonomy_level' => 'human_led', 'tool_overrides' => '{}', 'state' => '{}',
            'clean_drafts_count' => TrustLedger::PROMOTION_GATE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $letter = $this->compose();

        $this->assertCount(1, $letter['trust']['promotions']);
        $promotion = $letter['trust']['promotions'][0];
        $this->assertSame(['cold-email-drafting', 'human_led', 'assisted'], [$promotion['skill_slug'], $promotion['from'], $promotion['to']]);
        $this->assertStringContainsString('20 clean drafts in a row', $promotion['evidence']);
        $this->assertStringContainsString('yours to approve, never automatic', $letter['markdown']);

        // Proposed only: the ladder has not moved.
        $this->assertSame('human_led', DB::table('tenant_skills')->where('tenant_id', $this->tenant->id)->value('autonomy_level'));
    }

    public function test_the_csuite_half_carries_wins_and_an_attributed_quote(): void
    {
        $run = $this->agentRun('cold-email-drafting', 'succeeded');
        $this->artifact($run, 'approved');

        $letter = $this->compose();
        $csuite = $letter['csuite'];

        $this->assertNotEmpty($csuite['wins']);
        $this->assertNotNull($csuite['quote']);
        $this->assertNotSame('', $csuite['quote']['attribution']);
        $this->assertContains($csuite['quote']['tradition'], QuoteBank::TRADITIONS);
        $this->assertArrayHasKey($csuite['mood'], QuoteBank::MOODS);

        // The C-Suite half is inside the Monday letter, quote and all.
        $this->assertStringContainsString('## The C-Suite letter', $letter['markdown']);
        $this->assertStringContainsString($csuite['quote']['text'], $letter['markdown']);
        $this->assertStringContainsString('— '.$csuite['quote']['attribution'], $letter['markdown']);
        $this->assertStringContainsString('Reply to this letter', $letter['markdown']);
    }

    public function test_the_quote_does_not_repeat_within_the_no_repeat_window(): void
    {
        $bank = app(QuoteBank::class);
        $tenantId = (string) $this->tenant->id;

        $first = $bank->pick($tenantId, 'focused', $tenantId.'|2026-W10');
        $this->assertNotNull($first);

        NewsletterIssue::create([
            'tenant_id' => $tenantId, 'kind' => NewsletterIssue::KIND_CSUITE, 'period' => '2026-W10',
            'quote_id' => $first['id'], 'markdown' => 'x',
        ]);

        $second = $bank->pick($tenantId, 'focused', $tenantId.'|2026-W11');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertContains($first['id'], $bank->recentlyUsed($tenantId));
    }

    public function test_the_same_week_composes_the_same_quote_twice(): void
    {
        $first = $this->compose();
        $second = $this->compose();

        $this->assertSame($first['csuite']['quote']['id'], $second['csuite']['quote']['id']);
        // Composing twice files one issue for the week, not two.
        $this->assertSame(1, NewsletterIssue::forTenant((string) $this->tenant->id)->count());
    }

    public function test_both_halves_are_filed_in_the_knowledge_brain(): void
    {
        $letter = $this->compose();
        $period = $letter['period'];

        $this->assertSame("reports/weekly/{$period}.md", $letter['paths']['letter']);
        $this->assertSame("reports/weekly/{$period}-csuite.md", $letter['paths']['csuite']);

        $paths = DB::table('brain_files')->where('tenant_id', $this->tenant->id)->pluck('path')->all();
        $this->assertContains("reports/weekly/{$period}.md", $paths);
        $this->assertContains("reports/weekly/{$period}-csuite.md", $paths);

        $issue = NewsletterIssue::forTenant((string) $this->tenant->id)->firstOrFail();
        $this->assertSame([NewsletterIssue::KIND_CSUITE, $period, "reports/weekly/{$period}-csuite.md"],
            [$issue->kind, $issue->period, $issue->brain_path]);
        // Internal by construction: it never goes to a list.
        $this->assertSame(NewsletterIssue::CHANNEL_COCKPIT, $issue->channel);
    }

    // ------------------------------------------------------------------ //
    //  API
    // ------------------------------------------------------------------ //

    public function test_the_api_serves_the_letter_and_the_filed_issues(): void
    {
        $run = $this->agentRun('cold-email-drafting', 'succeeded');
        $this->artifact($run, 'approved');

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/reports/weekly')->assertOk();
        $period = $response->json('data.period');

        $this->assertSame($this->weekStart->format('o-\WW'), $period);
        $this->assertNotEmpty($response->json('data.markdown'));
        $this->assertSame(WeeklyNumbers::KEYS, array_keys($response->json('data.numbers')));

        $this->actingAs($this->user, 'sanctum')->getJson("/api/reports/weekly/{$period}")
            ->assertOk()->assertJsonPath('data.period', $period);

        $this->actingAs($this->user, 'sanctum')->getJson('/api/reports/weekly/not-a-week')->assertNotFound();
        $this->actingAs($this->user, 'sanctum')->getJson('/api/reports/weekly/2026-W99')
            ->assertStatus(422)->assertJsonPath('reason', 'bad_period');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/reports/newsletters')
            ->assertOk()->assertJsonPath('data.0.kind', NewsletterIssue::KIND_CSUITE);
    }

    public function test_the_weekly_routes_require_authentication(): void
    {
        $this->getJson('/api/reports/weekly')->assertUnauthorized();
        $this->getJson('/api/reports/newsletters')->assertUnauthorized();
    }

    // ------------------------------------------------------------------ //
    //  Delivery
    // ------------------------------------------------------------------ //

    public function test_the_sweep_only_fires_on_monday_morning_in_the_tenants_own_timezone(): void
    {
        // 07:00 Monday in Harare is 05:00 UTC; an hour either side is not the letter's hour.
        $this->assertTrue(MondayLetterJob::dueNow($this->tenant, Carbon::parse('2026-09-21 05:00:00', 'UTC')));
        $this->assertFalse(MondayLetterJob::dueNow($this->tenant, Carbon::parse('2026-09-21 06:00:00', 'UTC')));
        $this->assertFalse(MondayLetterJob::dueNow($this->tenant, Carbon::parse('2026-09-22 05:00:00', 'UTC')));

        // A tenant in another zone takes a different tick of the same sweep.
        $this->tenant->forceFill(['settings' => ['timezone' => 'America/New_York']])->save();
        $this->assertFalse(MondayLetterJob::dueNow($this->tenant->fresh(), Carbon::parse('2026-09-21 05:00:00', 'UTC')));
        $this->assertTrue(MondayLetterJob::dueNow($this->tenant->fresh(), Carbon::parse('2026-09-21 11:00:00', 'UTC')));
    }

    public function test_the_sweep_composes_once_and_does_not_resend(): void
    {
        $this->flag('on');
        $run = $this->agentRun('cold-email-drafting', 'succeeded');
        $this->artifact($run, 'approved');
        $tenantId = (string) $this->tenant->id;

        (new MondayLetterJob($tenantId))->handle(app(MondayLetterComposer::class), app(NotificationService::class));

        $issue = NewsletterIssue::forTenant($tenantId)->firstOrFail();
        $this->assertSame(NewsletterIssue::STATUS_SENT, $issue->status);
        $this->assertNotNull($issue->sent_at);
        $sentAt = $issue->sent_at;

        (new MondayLetterJob($tenantId))->handle(app(MondayLetterComposer::class), app(NotificationService::class));

        $this->assertSame(1, NewsletterIssue::forTenant($tenantId)->count());
        $this->assertTrue($sentAt->equalTo(NewsletterIssue::forTenant($tenantId)->firstOrFail()->sent_at));
    }

    public function test_the_flag_gates_the_sweep(): void
    {
        $this->flag('off');

        (new MondayLetterJob((string) $this->tenant->id))->handle(app(MondayLetterComposer::class), app(NotificationService::class));

        $this->assertSame(0, NewsletterIssue::forTenant((string) $this->tenant->id)->count());
    }

    public function test_one_tenant_never_reads_anothers_letter(): void
    {
        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other Co', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $otherUser = User::create([
            'name' => 'Other', 'email' => 'o-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $other->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $run = $this->agentRun('cold-email-drafting', 'succeeded');
        $this->artifact($run, 'approved');

        $response = $this->actingAs($otherUser, 'sanctum')->getJson('/api/reports/weekly')->assertOk();

        $this->assertSame([], $response->json('data.trust.rows'));
        $this->assertSame(0, $response->json('data.numbers.agent_cost.extra.runs'));
    }
}
