<?php

declare(strict_types=1);

namespace Tests\Feature\Board;

use App\Models\BoardSession as SessionModel;
use App\Models\BoardTake;
use App\Models\BrainFile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Board\AdvisorRegistry;
use App\Services\Board\BoardSession;
use App\Services\Board\VerdictSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The converged board protocol (plan D6 §6): isolated round 1, anonymised
 * round 2, chairman synthesis with the minority report carried verbatim, and
 * the likeness rules that keep an archetype from becoming an impersonation.
 */
class BoardSessionProtocolTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    /** @var list<HttpRequest> */
    private array $sent = [];

    /** @var callable|null what the seats answer; swapped per test */
    private $seatReply = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Board Co', 'slug' => 'board-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $this->user = User::create([
            'name' => 'Jason', 'email' => 'j-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        config()->set('services.inference.url', 'http://inference.test');
        $this->flags(['board.enabled' => 'on']);
        $this->fakePlane();
    }

    private function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    /**
     * Every seat answers "yes" except the one whose prompt shows it is the
     * Greenlight, which says no — so there is always exactly one dissent to
     * carry.
     *
     * Http::fake() accumulates stubs rather than replacing them, and the first
     * registered match wins, so a test cannot simply re-fake over setUp's stub.
     * One stub is registered, once, and it consults $seatReply — which a test
     * can swap at any point.
     */
    private function seatReplies(?callable $seatReply): void
    {
        $this->seatReply = $seatReply;
    }

    private function fakePlane(?callable $seatReply = null): void
    {
        $this->sent = [];
        $this->seatReply = $seatReply;

        Http::fake(['*/generate' => function (HttpRequest $request) {
            $seatReply = $this->seatReply;
            $this->sent[] = $request;
            $system = (string) ($request->data()['system_prompt'] ?? '');
            $prompt = (string) ($request->data()['prompt'] ?? '');

            if (str_contains($system, 'The Chairman')) {
                return Http::response(['text' => json_encode([
                    'consensus' => 'Four seats say go, one says not yet on the cash timing.',
                    'recommended_action' => 'Run the offer past ten existing customers this week before spending anything.',
                    'next_check_date' => '2026-10-05',
                ]), 'model' => 'test', 'tokens_used' => 300, 'cost' => 0.004, 'provider' => 'test']);
            }

            if ($seatReply !== null) {
                return $seatReply($system, $prompt);
            }

            $dissents = str_contains($system, 'The Greenlight');

            return Http::response(['text' => json_encode([
                'stance' => $dissents ? 'not_yet' : 'yes',
                'confidence' => $dissents ? 0.8 : 0.6,
                'one_number' => $dissents ? 'months of runway at the current burn' : 'replies per 100 emails',
                'what_would_change_my_mind' => 'a month of real numbers',
                'kill_criteria' => ['under 3 replies per 100 by the review date'],
                'reasoning' => $dissents
                    ? 'The cash does not cover the mistake if this is wrong. Say not yet and revisit in a month.'
                    : 'The offer is sound and the market is there.',
            ]), 'model' => 'test', 'tokens_used' => 200, 'cost' => 0.002, 'provider' => 'test']);
        }]);
    }

    private function brainFile(string $path, string $content): void
    {
        BrainFile::create([
            'tenant_id' => $this->tenant->id, 'path' => $path, 'title' => $path,
            'content' => $content, 'frontmatter' => [], 'source' => 'human',
            'data_class' => 'internal', 'version' => 1, 'content_hash' => hash('sha256', $content),
        ]);
    }

    private function convene(string $question = 'Should we raise the price of the monthly plan?'): SessionModel
    {
        return app(BoardSession::class)->convene((string) $this->tenant->id, $question, (string) $this->user->id);
    }

    // ------------------------------------------------------------------ //
    //  The seats
    // ------------------------------------------------------------------ //

    public function test_the_shipped_seats_are_archetypes_and_never_claim_to_be_the_person(): void
    {
        $advisors = app(AdvisorRegistry::class);
        $seats = $advisors->seatsFor((string) $this->tenant->id);

        $this->assertSame(
            ['offer-architect', 'producer', 'leverage-philosopher', 'compounder', 'greenlight'],
            array_column($seats, 'slug'),
        );

        foreach ($seats as $seat) {
            $prompt = $advisors->promptFor($seat);

            $this->assertStringContainsString('reason with the frameworks of', $prompt);
            $this->assertStringNotContainsString('You are Alex Hormozi', $prompt);
            $this->assertStringNotContainsString('You are Rick Rubin', $prompt);
            $this->assertStringNotContainsString('You are Naval', $prompt);
            $this->assertStringNotContainsString('You are Patrick', $prompt);
            $this->assertStringNotContainsString('You are Matthew', $prompt);
            $this->assertStringContainsString('You are not that person', $prompt);
            $this->assertStringContainsString('never invent a figure', strtolower($prompt));
            // No seat is voiced with a clone of anyone.
            $this->assertNotSame('cloned', $seat['likeness_mode']);
        }

        // Both of Jason's additions are on the board.
        $this->assertContains('The Compounder', array_column($seats, 'display_name'));
        $this->assertContains('The Greenlight', array_column($seats, 'display_name'));
    }

    public function test_the_private_roster_is_off_by_default_and_replaces_rather_than_doubles_the_board(): void
    {
        $advisors = app(AdvisorRegistry::class);
        $tenantId = (string) $this->tenant->id;

        $public = $advisors->seatsFor($tenantId);
        $this->assertSame([], array_filter($public, fn (array $s): bool => str_starts_with($s['slug'], 'private-')));

        $this->flags(['board.private_roster' => 'on']);
        $private = app(AdvisorRegistry::class)->seatsFor($tenantId);

        // Same number of seats: a private seat stands in for its archetype.
        $this->assertCount(count($public), $private);
        $this->assertContains('Alex Hormozi', array_column($private, 'display_name'));
        $this->assertNotContains('The Offer Architect', array_column($private, 'display_name'));

        // It thinks exactly like the archetype it extends, and still never
        // claims to be the person.
        $seat = collect($private)->firstWhere('slug', 'private-hormozi');
        $prompt = app(AdvisorRegistry::class)->promptFor($seat);
        $this->assertStringContainsString('reason with the frameworks of Alex Hormozi', $prompt);
        $this->assertStringContainsString('You are not that person', $prompt);
        $this->assertStringContainsString('value equation', strtolower($prompt));
    }

    // ------------------------------------------------------------------ //
    //  The protocol
    // ------------------------------------------------------------------ //

    public function test_round_one_is_isolated_and_round_two_is_anonymised(): void
    {
        $this->brainFile('offer/offer.md', "## Products and services\nMonthly bookkeeping from R1,800.");
        $this->brainFile('people/user.md', "## Who I am\nThandi, founder.");

        $session = $this->convene();

        $this->assertSame('complete', $session->status);

        $round1 = array_values(array_filter($this->sent, fn (HttpRequest $r): bool => ! str_contains((string) $r->data()['prompt'], 'THE OTHER SEATS')
            && ! str_contains((string) ($r->data()['system_prompt'] ?? ''), 'The Chairman')));
        $round2 = array_values(array_filter($this->sent, fn (HttpRequest $r): bool => str_contains((string) $r->data()['prompt'], 'THE OTHER SEATS')));

        $this->assertCount(5, $round1);
        $this->assertCount(5, $round2);

        // Round 1: no seat is shown anybody else's view.
        foreach ($round1 as $request) {
            $this->assertStringNotContainsString('THE OTHER SEATS', (string) $request->data()['prompt']);
            $this->assertStringContainsString('you have not seen any other seat', strtolower((string) $request->data()['prompt']));
        }

        // Round 2: the others appear as letters, with no names attached.
        foreach ($round2 as $request) {
            $prompt = (string) $request->data()['prompt'];
            $this->assertMatchesRegularExpression('/Seat [A-E] — /', $prompt);
            foreach (['The Offer Architect', 'The Producer', 'The Greenlight', 'Hormozi', 'Rubin', 'Ravikant', 'McConaughey'] as $name) {
                $this->assertStringNotContainsString($name, $prompt, "round 2 leaked \"{$name}\"");
            }
        }
    }

    public function test_a_seat_that_signs_its_own_reasoning_is_redacted_before_the_cross_exam(): void
    {
        // A seat that names itself (or its inspiration) in free text would
        // defeat the anonymisation that the whole round depends on.
        $this->seatReplies(function (string $system) {
            return Http::response(['text' => json_encode([
                'stance' => 'yes', 'confidence' => 0.7,
                'one_number' => 'replies per 100 emails',
                'what_would_change_my_mind' => 'a month of numbers',
                'kill_criteria' => [],
                'reasoning' => 'As The Offer Architect I would say this is a Hormozi-style grand slam offer.',
            ]), 'model' => 'test', 'tokens_used' => 100, 'cost' => 0.001, 'provider' => 'test']);
        });

        $this->convene();

        $round2 = array_values(array_filter($this->sent, fn (HttpRequest $r): bool => str_contains((string) $r->data()['prompt'], 'THE OTHER SEATS')));
        $this->assertNotEmpty($round2);

        foreach ($round2 as $request) {
            $prompt = (string) $request->data()['prompt'];
            $this->assertStringNotContainsString('Offer Architect', $prompt);
            $this->assertStringNotContainsString('Hormozi', $prompt);
            $this->assertStringContainsString('a seat', $prompt);
        }
    }

    public function test_the_minority_report_is_carried_verbatim(): void
    {
        $session = $this->convene();
        $verdict = $session->verdict;

        $this->assertNotNull($verdict);
        $this->assertSame('greenlight', $verdict->minority_seat);
        $this->assertSame(
            'The cash does not cover the mistake if this is wrong. Say not yet and revisit in a month.',
            $verdict->minority_report,
        );
        $this->assertSame('Four seats say go, one says not yet on the cash timing.', $verdict->consensus);
        $this->assertSame('2026-10-05', $verdict->next_check_date->toDateString());

        // The dissent appears in the report as a quote, unrewritten.
        $markdown = BoardSession::markdown($session, $verdict);
        $this->assertStringContainsString('## Minority report', $markdown);
        $this->assertStringContainsString('> The cash does not cover the mistake', $markdown);
        $this->assertStringContainsString('Not legal, financial or professional advice.', $markdown);
    }

    public function test_a_board_that_agrees_files_no_manufactured_dissent(): void
    {
        $this->seatReplies(fn () => Http::response(['text' => json_encode([
            'stance' => 'yes', 'confidence' => 0.7, 'one_number' => 'replies per 100',
            'what_would_change_my_mind' => 'a month of numbers', 'kill_criteria' => [], 'reasoning' => 'Sound.',
        ]), 'model' => 'test', 'tokens_used' => 100, 'cost' => 0.001, 'provider' => 'test']));

        $verdict = $this->convene()->verdict;

        $this->assertNull($verdict->minority_report);
        $this->assertNull($verdict->minority_seat);
    }

    public function test_one_failing_seat_does_not_fail_the_board(): void
    {
        // One seat's provider is down for the whole session, both rounds.
        $this->seatReplies(fn (string $system) => str_contains($system, 'The Producer')
            ? Http::response(['detail' => 'upstream down'], 503)
            : Http::response(['text' => json_encode([
                'stance' => 'yes', 'confidence' => 0.6, 'one_number' => 'replies per 100',
                'what_would_change_my_mind' => 'numbers', 'kill_criteria' => [], 'reasoning' => 'Sound enough.',
            ]), 'model' => 'test', 'tokens_used' => 100, 'cost' => 0.001, 'provider' => 'test']));

        $session = $this->convene();

        $this->assertSame('complete', $session->status);
        $this->assertNotNull($session->verdict);
        // Both of the Producer's rounds are recorded as failures, with the reason.
        $failed = BoardTake::where('session_id', $session->id)->whereNotNull('error')->get();
        $this->assertCount(2, $failed);
        $this->assertSame(['producer', 'producer'], $failed->pluck('seat')->all());

        // The other four still made the table, and the founder can see who was missing.
        $this->assertCount(4, $session->verdict->table_rows);
        $this->assertNotContains('producer', array_column($session->verdict->table_rows, 'seat'));
    }

    public function test_the_session_is_filed_in_the_brain_with_its_cost(): void
    {
        $session = $this->convene();

        $this->assertNotNull($session->brain_path);
        $this->assertStringStartsWith('reports/board/', $session->brain_path);
        $this->assertTrue(BrainFile::forTenant((string) $this->tenant->id)->where('path', $session->brain_path)->exists());
        // 5 seats x 2 rounds + the chair.
        $this->assertGreaterThan(0.0, (float) $session->cost_usd);
        $this->assertSame(11, count($this->sent));
    }

    public function test_the_brief_records_what_the_brain_was_missing(): void
    {
        $session = $this->convene();

        // Nothing was filled in, so every scope the seats asked for is missing
        // and the seats are told to say so rather than invent it.
        $this->assertNotEmpty($session->brief['missing']);
        $this->assertContains('offer/offer.md', $session->brief['missing']);

        $firstPrompt = (string) $this->sent[0]->data()['prompt'];
        $this->assertStringContainsString('The brain holds nothing in your scope yet', $firstPrompt);
    }

    // ------------------------------------------------------------------ //
    //  The verdict schema
    // ------------------------------------------------------------------ //

    public function test_the_verdict_schema_survives_fenced_json_and_rejects_nonsense(): void
    {
        $fenced = "Here is my view:\n```json\n".json_encode([
            'stance' => 'Not Yet', 'confidence' => 1.4, 'one_number' => 'runway months',
            'what_would_change_my_mind' => 'a month of data',
            'kill_criteria' => ['a', 'b', 'c', 'd', 'e'], 'reasoning' => 'Because.',
        ])."\n```";

        $parsed = VerdictSchema::parse($fenced);
        $this->assertTrue($parsed['ok']);
        $this->assertSame('not_yet', $parsed['verdict']['stance']);
        $this->assertSame(1.0, $parsed['verdict']['confidence']);
        $this->assertCount(VerdictSchema::MAX_KILL_CRITERIA, $parsed['verdict']['kill_criteria']);

        $prose = VerdictSchema::parse('I think you should probably go for it.');
        $this->assertFalse($prose['ok']);
        $this->assertSame(['the seat did not return JSON'], $prose['errors']);

        $wrongStance = VerdictSchema::parse(json_encode([
            'stance' => 'maybe', 'confidence' => 0.5, 'one_number' => 'x',
            'what_would_change_my_mind' => 'y', 'reasoning' => 'z',
        ]));
        $this->assertFalse($wrongStance['ok']);
        $this->assertSame('depends', $wrongStance['verdict']['stance']);
    }

    // ------------------------------------------------------------------ //
    //  API
    // ------------------------------------------------------------------ //

    public function test_the_api_gates_on_the_flag_and_serves_seats_sessions_and_the_report(): void
    {
        $this->flags(['board.enabled' => 'off']);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/board/seats')
            ->assertForbidden()->assertJsonPath('reason', 'board.disabled');

        $this->flags(['board.enabled' => 'on']);

        $seats = $this->actingAs($this->user, 'sanctum')->getJson('/api/board/seats')->assertOk();
        $this->assertCount(5, $seats->json('data'));
        $this->assertSame('Not legal, financial or professional advice.', $seats->json('meta.disclaimer'));
        $this->assertSame('chairman', $seats->json('meta.chair.slug'));
        $this->assertFalse($seats->json('meta.private_roster'));

        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/board/sessions', ['question' => 'Should we raise the monthly price by 20 percent?'])
            ->assertCreated();
        $slug = $created->json('data.slug');
        $this->assertSame('complete', $created->json('data.status'));
        $this->assertSame('greenlight', $created->json('data.verdict.minority_seat'));

        $this->actingAs($this->user, 'sanctum')->getJson("/api/board/sessions/{$slug}")
            ->assertOk()->assertJsonPath('data.slug', $slug)
            ->assertJsonCount(10, 'data.takes');

        $report = $this->actingAs($this->user, 'sanctum')->get("/api/board/sessions/{$slug}/report.md")->assertOk();
        $this->assertStringContainsString('text/markdown', $report->headers->get('Content-Type'));
        $this->assertStringContainsString('## Minority report', $report->getContent());

        $this->actingAs($this->user, 'sanctum')->getJson('/api/board/sessions')
            ->assertOk()->assertJsonPath('data.0.slug', $slug);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/board/sessions', ['question' => 'too short'])->assertStatus(422);
    }

    public function test_the_board_routes_require_authentication(): void
    {
        $this->getJson('/api/board/seats')->assertUnauthorized();
        $this->getJson('/api/board/sessions')->assertUnauthorized();
        $this->postJson('/api/board/sessions', ['question' => 'Should we raise the price?'])->assertUnauthorized();
    }

    public function test_one_tenant_cannot_read_anothers_session(): void
    {
        $session = $this->convene();

        $other = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Other Co', 'slug' => 'other-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $otherUser = User::create([
            'name' => 'Other', 'email' => 'o-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $other->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        $this->actingAs($otherUser, 'sanctum')->getJson("/api/board/sessions/{$session->id}")->assertNotFound();
        $this->actingAs($otherUser, 'sanctum')->get("/api/board/sessions/{$session->slug}/report.md")->assertNotFound();
        $this->actingAs($otherUser, 'sanctum')->getJson('/api/board/sessions')->assertOk()->assertJsonCount(0, 'data');
    }
}
