<?php

declare(strict_types=1);

namespace Tests\Feature\Launch;

use App\Models\BusinessLaunch;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The interview half of /api/launch (plan D7 §5): the flag gate, purchased →
 * interviewing, stages.yaml driving which question is asked next, skipping an
 * optional question, the "Now filling" brain path, progress and the
 * disclaimer that every payload has to carry.
 */
class LaunchInterviewTest extends LaunchTestCase
{
    use RefreshDatabase;

    public function test_every_launch_route_is_404_while_the_flag_is_off(): void
    {
        $this->flags(['launch.enabled' => 'off']);

        $this->actingAsOwner()->getJson('/api/launch')->assertNotFound();
        $this->actingAsOwner()->postJson('/api/launch/start')->assertNotFound();
        $this->actingAsOwner()->postJson('/api/launch/answer', ['answer' => 'hello'])->assertNotFound();
        $this->actingAsOwner()->getJson('/api/launch/jurisdictions/uk/checklist')->assertNotFound();
    }

    public function test_show_before_start_reports_not_started_with_the_jurisdictions_on_offer(): void
    {
        $data = $this->actingAsOwner()->getJson('/api/launch')->assertOk()->json('data');

        $this->assertFalse($data['started']);
        $this->assertNull($data['status']);
        $this->assertSame(['uk', 'za', 'zw'], $data['jurisdictions']);
        $this->assertSame('Not legal or financial advice.', $data['disclaimer']);
    }

    public function test_start_moves_purchased_to_interviewing_and_asks_the_first_question(): void
    {
        $data = $this->startLaunch('uk');

        $this->assertTrue($data['started']);
        $this->assertSame(BusinessLaunch::STATUS_INTERVIEWING, $data['status']);
        $this->assertSame('profile', $data['stage']);
        $this->assertSame('uk', $data['jurisdiction']);
        $this->assertSame('business_name', $data['next_question']['id']);
        $this->assertSame('identity.business_name', $data['next_question']['variable']);
        $this->assertTrue($data['next_question']['required']);
        $this->assertSame('business/profile.md', $data['now_filling']);
        $this->assertSame(0, $data['progress_pct']);
        $this->assertStringContainsString('Not legal or financial advice', $data['disclaimer']);

        $launch = BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
        $this->assertSame('business-launch', $launch->pack_id);
        $this->assertSame([], $launch->interview_answers);
    }

    public function test_start_is_idempotent_and_keeps_answers_already_given(): void
    {
        $this->startLaunch('uk');
        $this->answer('business_name', 'Tidy Tuesdays');

        $again = $this->startLaunch('uk');

        $this->assertSame(1, BusinessLaunch::forTenant($this->tenant->id)->count());
        $this->assertSame('one_liner', $again['next_question']['id']);
    }

    public function test_answers_walk_the_stage_in_order_and_move_the_now_filling_file(): void
    {
        $this->startLaunch('uk');

        $after = $this->answer('business_name', 'Tidy Tuesdays');
        $this->assertTrue($after['recorded']);
        $this->assertSame('one_liner', $after['next_question']['id']);
        $this->assertSame('business/profile.md', $after['now_filling']);

        $after = $this->answer('one_liner', 'Weekly office cleaning for small studios in Leeds.');
        $this->assertSame('founder_why', $after['next_question']['id']);
        $this->assertSame('business/alignment.md', $after['now_filling']);
        $this->assertGreaterThan(0, $after['progress_pct']);

        $launch = BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
        $this->assertSame('Tidy Tuesdays', $launch->interview_answers['business_name']['answer']);
        $this->assertArrayHasKey('answered_at', $launch->interview_answers['business_name']);
    }

    public function test_an_empty_answer_is_recorded_as_a_skip_and_is_never_asked_again(): void
    {
        $this->startLaunch('uk');
        foreach (['business_name', 'one_liner', 'founder_why', 'mission', 'vision', 'ninety_day_target'] as $id) {
            $this->answer($id, self::REQUIRED_ANSWERS[$id]);
        }

        // stage_today is the profile stage's only optional variable.
        $state = $this->actingAsOwner()->getJson('/api/launch')->assertOk()->json('data');
        $this->assertSame('stage_today', $state['next_question']['id']);
        $this->assertFalse($state['next_question']['required']);

        $after = $this->answer('stage_today', '');
        $this->assertTrue($after['skipped']);
        $this->assertNotSame('stage_today', $after['next_question']['id']);

        $launch = BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
        $this->assertTrue($launch->interview_answers['stage_today']['skipped']);
        $this->assertArrayNotHasKey('stage_today', $launch->answerValues());
    }

    public function test_an_unknown_question_id_is_refused(): void
    {
        $this->startLaunch('uk');

        $this->actingAsOwner()
            ->postJson('/api/launch/answer', ['question_id' => 'not_a_question', 'answer' => 'x'])
            ->assertStatus(422);
    }

    public function test_answering_the_jurisdiction_question_sets_the_launch_jurisdiction(): void
    {
        $this->startLaunch(null);
        $this->assertNull(BusinessLaunch::forTenant($this->tenant->id)->firstOrFail()->jurisdiction);

        $this->answer('jurisdiction', 'South Africa');

        $this->assertSame('za', BusinessLaunch::forTenant($this->tenant->id)->firstOrFail()->jurisdiction);
    }

    public function test_the_whole_interview_completes_and_hands_over_to_generation(): void
    {
        $this->startLaunch('uk');
        $state = $this->answerWholeInterview();

        $this->assertNull($state['next_question']);
        $this->assertNull($state['now_filling']);
        $this->assertGreaterThan(70, $state['progress_pct']);
        $this->assertLessThan(100, $state['progress_pct'], 'the generated artefacts are not built yet');

        $stages = collect($state['stages'])->keyBy('id');
        $this->assertTrue($stages['profile']['committed']);
        $this->assertTrue($stages['finance']['committed']);
        $this->assertSame([], $stages['gtm']['missing_required']);
        // The plan stage owns only generated artefacts, so it stays incomplete.
        $this->assertFalse($stages['plan']['complete']);
    }
}
