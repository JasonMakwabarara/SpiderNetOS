<?php

declare(strict_types=1);

namespace Tests\Feature\Launch;

use App\Models\BusinessLaunch;
use App\Models\FunnelSetup;
use App\Services\Interviews\ArrayAnswerStore;
use App\Services\Interviews\InterviewRunner;
use App\Services\Interviews\ModelAnswerStore;
use App\Services\Sales\FunnelSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * App\Services\Interviews\InterviewRunner, extracted from FunnelSetupService
 * (plan D7 cross-cutting prerequisites) and parameterised by pack id +
 * answer store.
 *
 * The contract that matters: one runner serves both packs, and the sales-crm
 * funnel behaves exactly as it did before the extraction.
 */
class InterviewRunnerExtractionTest extends LaunchTestCase
{
    use RefreshDatabase;

    private function launchRunner(BusinessLaunch $launch): InterviewRunner
    {
        return new InterviewRunner('business-launch', new ModelAnswerStore($launch, 'interview_answers', null));
    }

    public function test_the_runner_reads_any_packs_files_without_a_row_behind_it(): void
    {
        $runner = new InterviewRunner('business-launch', new ArrayAnswerStore);

        $questions = $runner->loadInterviewQuestions();
        $this->assertSame(
            ['identity', 'offer', 'customers', 'brand', 'market', 'finance', 'compliance', 'gtm'],
            array_column($questions['sections'], 'id'),
        );

        $stages = $runner->loadPackFile('stages.yaml');
        $this->assertSame('profile', $stages['stages'][0]['id']);
        $this->assertSame([], $runner->loadPackFile('does/not/exist.yaml'));

        $question = $runner->findQuestion(null, 'starting_cash');
        $this->assertSame('money', $question['type']);
        $this->assertSame('finance/assumptions.yaml', $question['brain_target']['path']);
        $this->assertNull($runner->findQuestion(null, 'no_such_question'));
    }

    public function test_variables_are_section_dot_question_both_ways(): void
    {
        $runner = new InterviewRunner('business-launch', new ArrayAnswerStore);

        $this->assertSame('finance.starting_cash', $runner->variableFor('starting_cash'));
        $this->assertSame('identity', $runner->sectionIdFor('business_name'));
        $this->assertSame('starting_cash', $runner->questionIdForVariable('finance.starting_cash'));
        // The section has to match: a real question id under the wrong section is not a variable.
        $this->assertNull($runner->questionIdForVariable('identity.starting_cash'));
        $this->assertNull($runner->questionIdForVariable('starting_cash'));
    }

    public function test_recording_an_answer_writes_through_the_store_and_skips_are_not_variables(): void
    {
        $launch = BusinessLaunch::create([
            'tenant_id' => $this->tenant->id,
            'pack_id' => 'business-launch',
            'status' => BusinessLaunch::STATUS_INTERVIEWING,
            'interview_answers' => [],
            'stage_artifacts' => [],
        ]);
        $runner = $this->launchRunner($launch);

        $entry = $runner->recordAnswer('business_name', 'Tidy Tuesdays');
        $this->assertStringContainsString('what are you calling the business', $entry['question']);
        $this->assertSame('Tidy Tuesdays', $entry['answer']);

        $runner->recordAnswer('stage_today', '', true);

        $launch->refresh();
        $this->assertTrue($runner->isAnswered('stage_today'));
        $this->assertSame(['identity.business_name' => 'Tidy Tuesdays'], $runner->answeredVariables());
        $this->assertSame('Tidy Tuesdays', $launch->interview_answers['business_name']['answer']);
        $this->assertTrue($launch->interview_answers['stage_today']['skipped']);
    }

    public function test_the_array_store_round_trips_without_a_database(): void
    {
        $store = new ArrayAnswerStore;
        $runner = new InterviewRunner('business-launch', $store);

        $this->assertSame([], $runner->answers());
        $runner->recordAnswer('mission', 'Leave every studio spotless.');

        $this->assertSame('Leave every studio spotless.', $store->answers()['mission']['answer']);
        $this->assertSame('identity', $store->sectionId());
    }

    public function test_the_sales_crm_funnel_interview_still_behaves_exactly_as_before(): void
    {
        $service = app(FunnelSetupService::class);
        $setup = $service->getOrCreate($this->tenant->id);
        $this->assertSame('purchased', $setup->status);

        $setup = $service->beginInterview($setup);
        $this->assertSame('interviewing', $setup->status);
        $this->assertNotNull($setup->current_section);

        $first = $service->nextQuestion($setup);
        $this->assertFalse($first['done']);
        $this->assertArrayHasKey('id', $first['question']);
        $this->assertArrayHasKey('id', $first['section']);
        $this->assertSame(0, $first['progress_pct']);

        $setup = $service->recordAnswer($setup, $first['question']['id'], 'Design studios of five to fifteen people.');
        $this->assertSame(
            'Design studios of five to fifteen people.',
            $setup->interview_answers[$first['question']['id']]['answer'],
        );
        $this->assertSame($first['section']['id'], $setup->current_section);

        $second = $service->nextQuestion($setup);
        $this->assertNotSame($first['question']['id'], $second['question']['id']);
        $this->assertGreaterThan(0, $second['progress_pct']);

        $this->assertSame(1, FunnelSetup::forTenant($this->tenant->id)->count());
    }

    public function test_one_runner_serves_both_packs_side_by_side(): void
    {
        $sales = new InterviewRunner('sales-crm', new ArrayAnswerStore);
        $launch = new InterviewRunner('business-launch', new ArrayAnswerStore);

        $salesSections = array_column($sales->loadInterviewQuestions()['sections'], 'id');
        $launchSections = array_column($launch->loadInterviewQuestions()['sections'], 'id');

        $this->assertNotEmpty($salesSections);
        $this->assertNotEmpty($launchSections);
        $this->assertNotSame($salesSections, $launchSections);
        $this->assertSame('sales-crm', $sales->packId());
        $this->assertSame('business-launch', $launch->packId());
    }
}
