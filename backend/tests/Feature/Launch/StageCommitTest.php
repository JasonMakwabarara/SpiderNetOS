<?php

declare(strict_types=1);

namespace Tests\Feature\Launch;

use App\Models\BrainFile;
use App\Models\BusinessLaunch;
use App\Services\Brain\BrainStore;
use App\Services\Launch\StageCommitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Stage commits (stages.yaml → BrainStore): the founder's answers become
 * brain files with source `human`, one version write per file per stage, the
 * `launch.stage.committed` event, the disclaimer on every file, and the
 * refusal to commit a stage whose required variables are still missing.
 */
class StageCommitTest extends LaunchTestCase
{
    use RefreshDatabase;

    public function test_answering_a_stage_writes_its_brain_files_once_with_source_human(): void
    {
        $this->startLaunch('uk');
        foreach (['business_name', 'one_liner', 'founder_why', 'mission', 'vision', 'ninety_day_target'] as $id) {
            $this->answer($id, self::REQUIRED_ANSWERS[$id]);
        }

        $profile = BrainFile::forTenant($this->tenant->id)->where('path', 'business/profile.md')->first();
        $alignment = BrainFile::forTenant($this->tenant->id)->where('path', 'business/alignment.md')->first();

        $this->assertNotNull($profile, 'the profile stage should have written business/profile.md');
        $this->assertNotNull($alignment);
        $this->assertSame(BrainFile::SOURCE_HUMAN, $profile->source);
        $this->assertSame(1, (int) $profile->version, 'a stage is exactly one version write per file');
        $this->assertStringContainsString('## What we do', $profile->content);
        $this->assertStringContainsString('Weekly office cleaning', $profile->content);
        $this->assertStringContainsString('Not legal or financial advice', $profile->content);
        $this->assertSame('Tidy Tuesdays', $profile->frontmatter['name'] ?? null);

        $this->assertStringContainsString('## Origin', $alignment->content);
        $this->assertStringContainsString('## 90-day target', $alignment->content);
    }

    public function test_the_commit_is_recorded_and_emits_launch_stage_committed(): void
    {
        $this->startLaunch('uk');
        foreach (['business_name', 'one_liner', 'founder_why', 'mission', 'vision', 'ninety_day_target'] as $id) {
            $this->answer($id, self::REQUIRED_ANSWERS[$id]);
        }

        $launch = BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
        $recorded = $launch->stage_artifacts['stages']['profile'] ?? null;

        $this->assertNotNull($recorded);
        $this->assertSame(1, $recorded['files']['business/profile.md']);
        $this->assertArrayHasKey('answers_hash', $recorded);

        $events = DB::table('event_log')
            ->where('tenant_id', $this->tenant->id)
            ->where('event_type', StageCommitter::EVENT_STAGE_COMMITTED)
            ->get();
        $this->assertGreaterThanOrEqual(1, $events->count());
        $payload = json_decode((string) $events->first()->payload, true);
        $this->assertSame('profile', $payload['stage']);
        $this->assertSame('Not legal or financial advice.', $payload['disclaimer']);
    }

    public function test_re_committing_an_unchanged_stage_does_not_bump_the_version(): void
    {
        $this->startLaunch('uk');
        foreach (['business_name', 'one_liner', 'founder_why', 'mission', 'vision', 'ninety_day_target'] as $id) {
            $this->answer($id, self::REQUIRED_ANSWERS[$id]);
        }

        $before = (int) BrainFile::forTenant($this->tenant->id)->where('path', 'business/profile.md')->value('version');

        $this->actingAsOwner()->postJson('/api/launch/stages/profile/commit')->assertOk();
        $this->actingAsOwner()->postJson('/api/launch/stages/profile/commit')->assertOk();

        $after = (int) BrainFile::forTenant($this->tenant->id)->where('path', 'business/profile.md')->value('version');
        $this->assertSame($before, $after);
    }

    public function test_committing_a_stage_whose_required_answers_are_missing_is_422_with_the_list(): void
    {
        $this->startLaunch('uk');
        $this->answer('business_name', 'Tidy Tuesdays');

        $response = $this->actingAsOwner()
            ->postJson('/api/launch/stages/offer/commit')
            ->assertStatus(422);

        $this->assertContains('offer.core_offer', $response->json('missing'));
    }

    public function test_an_unknown_stage_is_404(): void
    {
        $this->startLaunch('uk');

        $this->actingAsOwner()->postJson('/api/launch/stages/nonsense/commit')->assertNotFound();
    }

    public function test_the_finance_stage_writes_parsed_assumptions_as_yaml_with_the_jurisdiction_currency(): void
    {
        $this->startLaunch('uk');
        $this->answerWholeInterview();

        $assumptions = BrainFile::forTenant($this->tenant->id)->where('path', 'finance/assumptions.yaml')->first();
        $this->assertNotNull($assumptions);
        $this->assertStringContainsString('Not legal or financial advice', $assumptions->content);

        $parsed = Yaml::parse($assumptions->content);
        $this->assertSame('GBP', $parsed['currency']);
        $this->assertSame(10000.0, $parsed['starting_cash']);
        $this->assertSame(2000.0, $parsed['setup_costs']);
        $this->assertSame(100.0, $parsed['price_per_unit']);
        $this->assertSame(20.0, $parsed['units_month_one']);
        $this->assertSame(10.0, $parsed['monthly_growth_pct']);
        $this->assertSame(40.0, $parsed['cost_of_sale_pct']);
        $this->assertSame(1500.0, $parsed['fixed_monthly_costs']);
        // The founder's own words are kept beside the numbers.
        $this->assertSame('About 10,000', $parsed['captured']['starting_cash']);
    }

    public function test_a_later_stage_commit_keeps_the_sections_an_earlier_stage_wrote(): void
    {
        $this->startLaunch('uk');
        $this->answerWholeInterview();

        $profile = BrainFile::forTenant($this->tenant->id)->where('path', 'business/profile.md')->first();

        // "What we do" came from the identity stage, "What makes us
        // different" from the offer stage — both live in the same file.
        $this->assertStringContainsString('## What we do', $profile->content);
        $this->assertGreaterThanOrEqual(1, (int) $profile->version);
    }

    public function test_a_recommit_keeps_prose_the_interview_does_not_own(): void
    {
        $this->startLaunch('uk');
        foreach (['business_name', 'one_liner', 'founder_why', 'mission', 'vision', 'ninety_day_target'] as $id) {
            $this->answer($id, self::REQUIRED_ANSWERS[$id]);
        }

        // An agent writes a section of its own into a file the interview owns.
        app(BrainStore::class)->upsertSection(
            $this->tenant->id,
            'business/profile.md',
            'Research status',
            'Not researched yet — the founder has not looked at comparables.',
            BrainFile::SOURCE_AGENT,
            'test',
        );

        // stage_today is the profile stage's optional variable, so answering
        // it re-commits business/profile.md.
        $this->service()->answer($this->launchModel(), 'Two pilot clients already paying.', 'stage_today');

        $profile = BrainFile::forTenant($this->tenant->id)->where('path', 'business/profile.md')->first();
        $this->assertStringContainsString('## What we do', $profile->content);
        $this->assertStringContainsString('## Where we are today', $profile->content);
        $this->assertStringContainsString('## Research status', $profile->content);
        $this->assertStringContainsString('Not researched yet', $profile->content);
        $this->assertSame(1, substr_count($profile->content, 'Not legal or financial advice'));
    }

    public function test_numbers_are_read_out_of_plain_english(): void
    {
        $this->assertSame(5000.0, StageCommitter::numberFrom('about £5,000 to start'));
        $this->assertSame(12000.0, StageCommitter::numberFrom('12k'));
        $this->assertSame(5.0, StageCommitter::numberFrom('around 5%'));
        $this->assertSame(1500000.0, StageCommitter::numberFrom('1.5m'));
        $this->assertNull(StageCommitter::numberFrom('no idea yet'));
        $this->assertSame('za', StageCommitter::codeFrom('South Africa'));
        $this->assertSame('zw', StageCommitter::codeFrom('Zimbabwe'));
        $this->assertNull(StageCommitter::codeFrom('Namibia'));
    }
}
