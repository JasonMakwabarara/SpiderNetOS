<?php

declare(strict_types=1);

namespace Tests\Feature\Atlas;

use App\Http\Controllers\AtlasController;
use App\Models\AgentRun;
use App\Models\AtlasThread;
use App\Models\BrainFile;
use App\Models\Skill;
use App\Models\Tenant;
use App\Models\TenantSkill;
use App\Models\User;
use App\Services\AtlasClarityGate;
use App\Services\AtlasDiscoveryService;
use App\Services\AtlasPromptStack;
use App\Services\MetaPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * AtlasDiscoveryService::oneMoreQuestion (plan D8 "one step further"):
 * deterministic candidate scoring, novelty, skip/ack quiet turns, the
 * inline answer path, and the chat envelope's `one_step` behind the flag.
 */
class AtlasOneMoreQuestionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Step Co', 'slug' => 'step-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'onboarding_completed_at' => now(),
        ]);
        $this->user = User::create([
            'name' => 'U', 'email' => 'u-'.Str::lower(Str::random(6)).'@test.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);
        // A complete legacy profile so the profile question never competes.
        DB::table('tenant_business_profiles')->insert([
            'tenant_id' => $this->tenant->id, 'industry' => 'technology', 'employee_count_band' => '2-10',
            'issues_invoices' => true, 'biggest_time_drain' => 'outreach', 'discovery_complete_pct' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function thread(): AtlasThread
    {
        return AtlasThread::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'title' => 't', 'last_seen_at' => now()]);
    }

    private function skillCard(string $slug, array $requires = [], array $steps = []): Skill
    {
        return Skill::create([
            'slug' => $slug, 'version' => '1.0.0', 'name' => Str::headline($slug), 'pillar' => 'sales',
            'card' => ['slug' => $slug, 'brain' => ['requires' => $requires], 'one_step_further' => ['summary' => 'Goes one step further by proposing the follow-up cadence.', 'allow_dynamic' => false, 'steps' => $steps]],
        ]);
    }

    public function test_blocked_run_question_for_the_matched_skill_outranks_everything(): void
    {
        $thread = $this->thread();
        $this->skillCard('cold-email-drafting', [['path' => 'offer/offer.md', 'sections' => ['Pricing']]]);
        TenantSkill::create(['tenant_id' => $this->tenant->id, 'skill_slug' => 'cold-email-drafting', 'enabled' => true]);

        // A stale brain file (manifest: business/profile.md stale after 180 days).
        BrainFile::create(['tenant_id' => $this->tenant->id, 'path' => 'business/profile.md', 'title' => 'Business profile', 'content' => 'x', 'content_hash' => BrainFile::hashContent('x')]);
        DB::table('brain_files')->where('tenant_id', $this->tenant->id)->update(['updated_at' => now()->subDays(400)]);

        $run = AgentRun::create([
            'tenant_id' => $this->tenant->id, 'skill_slug' => 'cold-email-drafting', 'status' => 'blocked',
            'questions' => [['path' => 'offer/offer.md', 'section' => 'Pricing', 'question' => 'How is it priced — one-time, subscription, or custom quote?']],
        ]);
        $matched = (object) ['slug' => 'cold-email-drafting'];

        $service = app(AtlasDiscoveryService::class);
        $candidates = $service->oneStepCandidates((string) $this->tenant->id, 'cold-email-drafting');
        $this->assertSame('blocked_run', $candidates[0]['source']);
        $this->assertSame(3.0, $candidates[0]['score']);
        $stale = collect($candidates)->firstWhere('source', 'stale_section');
        $this->assertNotNull($stale);
        $this->assertSame('business/profile.md', $stale['path']);
        $this->assertSame(0.125, $stale['score'], 'catalogue-only path (0.25) × 7-day-old staleness (0.5)');
        // Knowledge-brain gaps (BrainGapAnalyzer): a path no card requires scores 0.25 × recency 1.0 × novelty 1.0;
        // the offer/offer.md#Pricing gap is folded into the blocked run (same section, more concrete).
        $gap = collect($candidates)->first(fn (array $c) => $c['source'] === 'brain_gap' && $c['path'] === 'business/profile.md');
        $this->assertNotNull($gap);
        $this->assertSame(0.25, $gap['score']);
        $this->assertNotEmpty($gap['question']);
        $this->assertCount(1, array_filter($candidates, fn (array $c) => $c['path'] === 'offer/offer.md' && $c['section'] === 'Pricing'));

        $result = $service->oneMoreQuestion((string) $this->tenant->id, (string) $this->user->id, $thread->id, 'Draft the Acme sequence', $matched);

        $this->assertNotNull($result);
        $this->assertSame('How is it priced — one-time, subscription, or custom quote? (or say skip)', $result['question']);
        $this->assertSame('blocked_run', $result['source']);
        $this->assertSame($run->id, $result['run_id']);
        $this->assertSame('offer/offer.md', $result['path']);
        $this->assertSame('Pricing', $result['section']);
        $this->assertSame(3.0, $result['score']);
        $this->assertNull($result['next_step']);

        $thread->refresh();
        $this->assertSame('run:offer/offer.md#Pricing', $thread->one_more_question_state['last_asked']);
        $this->assertSame(1, $thread->one_more_question_state['turns']);
        $this->assertCount(1, $thread->open_questions);

        // Same skill blocked but a different message match → 2.0.
        $other = $service->oneStepCandidates((string) $this->tenant->id, 'linkedin-outreach-specialist');
        $this->assertSame(2.0, $other[0]['score']);
    }

    public function test_novelty_turn_gap_skip_and_daily_budget_keep_it_from_nagging(): void
    {
        $thread = $this->thread();
        AgentRun::create(['tenant_id' => $this->tenant->id, 'skill_slug' => 'a', 'status' => 'waiting_input', 'questions' => ['First question?']]);
        AgentRun::create(['tenant_id' => $this->tenant->id, 'skill_slug' => 'b', 'status' => 'blocked', 'questions' => ['Second question?'], 'updated_at' => now()->subDays(3)]);
        $service = app(AtlasDiscoveryService::class);
        $t = (string) $this->tenant->id;

        $first = $service->oneMoreQuestion($t, null, $thread->id, 'Tell me about my pipeline');
        $this->assertSame('First question? (or say skip)', $first['question']);

        $this->assertNull($service->oneMoreQuestion($t, null, $thread->id, 'And what about deals?'), 'never on consecutive turns');

        $third = $service->oneMoreQuestion($t, null, $thread->id, 'Now the forecast please');
        $this->assertSame('Second question? (or say skip)', $third['question'], 'an asked question is not novel in this thread');

        $this->assertNull($service->oneMoreQuestion($t, null, $thread->id, 'skip'));
        $this->assertNull($service->oneMoreQuestion($t, null, $thread->id, 'What else is blocked?'), 'skip buys quiet turns');
        $this->assertNull($service->oneMoreQuestion($t, null, $thread->id, '/status'), 'slash commands never get a question');
        $this->assertNull($service->oneMoreQuestion($t, null, $thread->id, 'ok thanks'), 'acknowledgements never get a question');

        // The same keys are not novel in a second thread for 7 days (unanswered elsewhere):
        // neither run question comes back; a lower-value brain gap (novel) may.
        $second = $this->thread();
        $elsewhere = $service->oneMoreQuestion($t, null, $second->id, 'Hello, what should we do next?');
        $this->assertNotContains($elsewhere['question'] ?? null, ['First question? (or say skip)', 'Second question? (or say skip)']);
        if ($elsewhere !== null) {
            $this->assertContains($elsewhere['source'], ['brain_gap', 'user_step']);
        }

        // Daily budget: a fresh thread with plenty of candidates stops at 5 a day.
        $busy = $this->thread();
        foreach (range(1, 8) as $i) {
            AgentRun::create(['tenant_id' => $t, 'skill_slug' => "s{$i}", 'status' => 'blocked', 'questions' => ["Budget question {$i}?"]]);
        }
        $asked = 0;
        foreach (range(1, 16) as $i) {
            if ($service->oneMoreQuestion($t, null, $busy->id, "Turn number {$i} with real content") !== null) {
                $asked++;
            }
        }
        $this->assertSame(AtlasDiscoveryService::ONE_STEP_MAX_PER_DAY, $asked);
    }

    public function test_answer_mode_writes_the_brain_section_and_a_user_step_joins_next_steps(): void
    {
        $thread = $this->thread();
        $service = app(AtlasDiscoveryService::class);
        $t = (string) $this->tenant->id;
        AgentRun::create(['tenant_id' => $t, 'skill_slug' => 'cold-email-drafting', 'status' => 'blocked', 'questions' => [['path' => 'offer/offer.md', 'section' => 'Pricing', 'question' => 'How is it priced?']]]);

        $asked = $service->oneMoreQuestion($t, null, $thread->id, 'Draft the sequence');
        $this->assertSame('offer/offer.md', $asked['path']);

        $this->assertNull($service->oneMoreQuestion($t, null, $thread->id, 'Subscription, $49 per seat per month.', null, 'answer'));

        $file = BrainFile::forTenant($t)->where('path', 'offer/offer.md')->first();
        $this->assertNotNull($file, 'the answer is written to the brain section');
        $this->assertStringContainsString("## Pricing\n\nSubscription, $49 per seat per month.", $file->content);
        $this->assertSame('human', $file->source);
        $thread->refresh();
        $this->assertNotEmpty($thread->one_more_question_state['asked']['run:offer/offer.md#Pricing']['answered_at']);
        $this->assertNotEmpty($thread->open_questions[0]['answered_at']);

        // With nothing blocked and no next step, Atlas asks the user for one more step (D0 f)…
        AgentRun::query()->update(['status' => 'succeeded']);
        $fresh = $this->thread();
        $ask = $service->oneMoreQuestion($t, null, $fresh->id, 'Anything else worth doing?');
        $this->assertSame(AtlasPromptStack::USER_STEP_QUESTION, $ask['question']);
        $this->assertSame('user_step', $ask['source']);

        // …and the answer becomes a next_steps[] entry of origin user.
        $this->assertNull($service->oneMoreQuestion($t, null, $fresh->id, 'Call the three warm leads from last week', null, 'answer'));
        $fresh->refresh();
        $this->assertCount(1, $fresh->last_next_steps);
        $this->assertSame('user', $fresh->last_next_steps[0]['origin']);
        $this->assertSame('Call the three warm leads from last week', $fresh->last_next_steps[0]['does']);
        $this->assertSame('proposed', $fresh->last_next_steps[0]['state']);
    }

    public function test_next_step_comes_from_thread_runs_or_the_matched_card(): void
    {
        $service = app(AtlasDiscoveryService::class);
        $t = (string) $this->tenant->id;
        $card = $this->skillCard('cold-email-drafting', [], [
            ['id' => 'follow_ups', 'label' => 'Draft the follow-up cadence', 'does' => 'Three follow-ups over nine days.', 'skill' => 'follow-up-drafter', 'when' => 'on_success', 'risk' => 'write'],
        ]);

        $fromCard = $service->nextStepFor($t, null, 'cold-email-drafting', $card);
        $this->assertSame('card', $fromCard['origin']);
        $this->assertSame('Draft the follow-up cadence', $fromCard['label']);
        $this->assertSame('follow-up-drafter', $fromCard['skill']);

        $run = AgentRun::create(['tenant_id' => $t, 'skill_slug' => 'cold-email-drafting', 'status' => 'succeeded', 'finished_at' => now()->subHour(),
            'outputs' => ['next_steps' => [['id' => 'x', 'origin' => 'model', 'label' => 'Classify the replies', 'does' => 'Hot/warm/cold', 'skill' => 'inbox-triage-reply-classifier', 'state' => 'proposed']]]]);
        $fromRun = $service->nextStepFor($t, null, 'cold-email-drafting', $card);
        $this->assertSame('Classify the replies', $fromRun['label']);
        $this->assertSame($run->id, $fromRun['run_id']);

        $thread = $this->thread();
        $thread->last_next_steps = [['id' => 'done', 'label' => 'Old', 'state' => 'done'], ['id' => 'p', 'label' => 'Book the demo', 'does' => 'Two slots', 'origin' => 'card', 'state' => 'proposed']];
        $thread->save();
        $fromThread = $service->nextStepFor($t, $thread, null, null);
        $this->assertSame('Book the demo', $fromThread['label']);

        $this->assertSame(80000.0, AtlasDiscoveryService::parseCost('$60–80k/yr'));
        $this->assertSame(48000.0, AtlasDiscoveryService::parseCost('$2–4k/mo'));
        $this->assertSame(0.0, AtlasDiscoveryService::parseCost('never gets done'));
    }

    public function test_chat_envelope_carries_one_step_only_when_the_flag_is_on(): void
    {
        $thread = $this->thread();
        AgentRun::create(['tenant_id' => $this->tenant->id, 'skill_slug' => 'cold-email-drafting', 'status' => 'blocked', 'questions' => ['Which segment first?']]);

        $captured = [];
        $this->mock(AtlasClarityGate::class, function ($mock) {
            $mock->shouldReceive('assess')->andReturn(['mode' => 'act', 'confidence' => 0.95]);
            $mock->shouldReceive('recordRefinementSignal')->zeroOrMoreTimes();
        });
        $this->mock(MetaPlanner::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('processAtlasRequest')->andReturnUsing(function (...$args) use (&$captured) {
                $captured[] = $args;

                return ['status' => 'dispatched', 'agent_id' => 'atlas', 'cost_status' => null];
            });
            $mock->shouldReceive('parseCommandToAst')->andReturn(['type' => 'chat']);
        });
        $this->partialMock(AtlasDiscoveryService::class, function ($mock) {
            $mock->shouldReceive('evaluate')->andReturn(['mode' => 'act', 'profile_pct' => 100]);
        });

        $request = function (array $body) use ($thread): Request {
            $request = Request::create('/api/atlas/chat', 'POST', $body + ['thread_id' => $thread->id]);
            $request->setUserResolver(fn () => $this->user);
            $request->attributes->set('tenant_id', (string) $this->tenant->id);
            $request->attributes->set('tenant', $this->tenant);

            return $request;
        };

        config(['features' => array_merge((array) config('features'), ['atlas.openjarvis' => 'off', 'atlas.one_more_question' => 'off'])]);
        $off = app(AtlasController::class)->chat($request(['message' => 'Draft the Acme outreach sequence']))->getData(true);
        $this->assertNull($off['one_step']);
        $this->assertArrayNotHasKey('one_step', $captured[0]['context'] ?? $captured[0][4] ?? []);

        config(['features' => array_merge((array) config('features'), ['atlas.one_more_question' => 'on'])]);
        // FeatureFlag caches the resolved value for 5 s (array cache in tests; no Redis here).
        \Illuminate\Support\Facades\Cache::forget('featureflag:atlas.one_more_question:t:'.$this->tenant->id);
        \Illuminate\Support\Facades\Cache::forget('featureflag:atlas.one_more_question');
        $on = app(AtlasController::class)->chat($request(['message' => 'Draft the Acme outreach sequence for Q4']))->getData(true);

        $this->assertSame('Which segment first? (or say skip)', $on['one_step']['question']);
        $this->assertArrayHasKey('next_step', $on['one_step']);
        $this->assertSame($thread->id, $on['one_step']['thread_id']);
        $context = $captured[1]['context'] ?? $captured[1][4];
        $this->assertSame('Which segment first? (or say skip)', $context['one_step']['question']);
        $this->assertStringContainsString('<ONE_MORE_QUESTION>', $context['system_prompt']);
        $this->assertStringContainsString(AtlasPromptStack::ONE_STEP_RULE, $context['system_prompt']);

        // Skip: the envelope stays quiet and the thread records the skip.
        $skipped = app(AtlasController::class)->chat($request(['message' => 'skip']))->getData(true);
        $this->assertNull($skipped['one_step']);
        $this->assertNotEmpty($thread->refresh()->one_more_question_state['asked']['run:'.AgentRun::first()->id.':0']['skipped_at']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
