<?php

declare(strict_types=1);

namespace Tests\Feature\Brain;

use App\Models\BrainFile;
use App\Models\BusinessProcess;
use App\Models\BusinessSystem;
use App\Models\FunnelSetup;
use App\Models\Sop;
use App\Models\Tenant;
use App\Models\TenantAlignmentProfile;
use App\Models\User;
use App\Services\Brain\BrainMarkdown;
use App\Services\Brain\BrainStore;
use App\Services\Brain\BrainSyncService;
use App\Services\EventStore;
use App\Services\Outreach\OutreachSettings;
use App\Services\Projections\BrainProjection;
use App\Services\Sales\FunnelSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BrainSyncService projects the tables into managed blocks: interview
 * answers + profile render into files, human prose outside the blocks
 * survives a re-sync, programs/affiliate.md carries the OutreachSettings
 * program facts, people/user.md is written for the primary admin, and the
 * FunnelSetupService::recordAnswer() hook projects progressively.
 */
class BrainSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWERS = [
        'origin_story' => 'Started to fix scheduling for clinics after watching my sister lose patients to no-shows.',
        'mission' => 'Never leave a patient message unanswered for more than an hour.',
        'vision' => 'Every small clinic in the country running its front desk on our platform.',
        'ninety_day_target' => '30 new paying clinics',
        'core_offer' => 'Appointment scheduling software for clinics',
        'pricing_model' => 'Subscription, $99/month',
        'proof_points' => "Cut no-shows by 40%\nRated 4.9 on Capterra",
        'ideal_customer' => 'A 2-5 doctor clinic whose front desk drowns in phone calls and double bookings.',
        'disqualifiers' => 'Solo practitioners and hospital groups.',
        'common_objection' => 'We already use a paper calendar',
        'preferred_tone' => 'warm and professional',
        'response_ownership' => 'draft for me to approve',
        'team_size' => '3-10 people',
    ];

    private Tenant $tenant;

    private User $admin;

    private BrainSyncService $sync;

    private BrainStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Clinic Scheduler', 'slug' => 'clinic-'.Str::lower(Str::random(6)),
            'domain' => 'clinicscheduler.test', 'status' => 'active', 'plan' => 'pro',
            'onboarding_completed_at' => now(), 'settings' => ['timezone' => 'Africa/Harare'],
        ]);

        $this->admin = User::create([
            'name' => 'Jason M', 'email' => 'jason@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
            'preferences' => ['calendar_link' => 'https://cal.com/jason/15min', 'meeting_types' => ['15-minute intro', '45-minute demo'], 'preferred_channel' => 'whatsapp'],
        ]);
        // A later member must not become "the user".
        User::create([
            'name' => 'Ops Member', 'email' => 'ops@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'member', 'onboarding_completed_at' => now(),
        ]);

        $this->sync = app(BrainSyncService::class);
        $this->store = app(BrainStore::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedBusinessContext(): FunnelSetup
    {
        DB::table('tenant_business_profiles')->insert([
            'tenant_id' => $this->tenant->id, 'industry' => 'healthcare', 'employee_count_band' => '2-10', 'country' => 'ZW',
            'region' => 'Harare', 'biggest_time_drain' => 'Chasing no-shows by phone',
            'pain_points' => json_encode(['double bookings', 'no-shows']), 'discovery_complete_pct' => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TenantAlignmentProfile::create([
            'tenant_id' => $this->tenant->id,
            'origin_story' => self::ANSWERS['origin_story'],
            'mission' => self::ANSWERS['mission'],
            'vision' => self::ANSWERS['vision'],
            'values' => ['care', 'speed'],
            'ninety_day_targets' => [['metric' => 'New paying clinics', 'goal' => '30', 'owner_role' => 'founder']],
        ]);

        $answers = [];
        foreach (self::ANSWERS as $id => $answer) {
            $answers[$id] = ['question' => $id, 'answer' => $answer, 'answered_at' => now()->toIso8601String()];
        }

        return FunnelSetup::create([
            'tenant_id' => $this->tenant->id, 'pack_id' => 'sales-crm', 'status' => 'interviewing', 'interview_answers' => $answers,
        ]);
    }

    private function seedOutreach(): void
    {
        $settings = app(OutreachSettings::class);
        $settings->seedDefaults($this->tenant);
        $settings->update($this->tenant, ['program' => ['join_url' => 'https://hannah.affonso.io/?group=grp1', 'postal_address' => '1 Test Street, Harare']]);
        $this->tenant->refresh();
    }

    public function test_sync_all_projects_interview_answers_and_profile_into_files(): void
    {
        $this->seedBusinessContext();
        $this->seedOutreach();

        $written = $this->sync->syncAll((string) $this->tenant->id);

        foreach (['business/profile.md', 'business/alignment.md', 'offer/offer.md', 'customers/icp.md', 'customers/objections.md', 'brand/voice.md', 'processes/follow-ups.md', 'programs/affiliate.md', 'people/user.md', 'finance/summary.md', 'market/comparables.md'] as $path) {
            $this->assertContains($path, $written, "{$path} was written");
        }

        $profile = $this->store->read((string) $this->tenant->id, 'business/profile.md');
        $this->assertSame('projection', $profile->source);
        $this->assertTrue($profile->managed, 'nothing but blocks and markers yet → wholly managed');
        $this->assertSame('Clinic Scheduler', $profile->frontmatter['name']);
        $this->assertSame('healthcare', $profile->frontmatter['industry']);
        $this->assertSame('Apex Synchronia LLC', $profile->frontmatter['legal_operator']);
        $this->assertTrue(BrainMarkdown::hasManagedBlock($profile->content, 'profile.what_we_do'));
        $sections = BrainMarkdown::sections($profile->content);
        $this->assertStringContainsString('Appointment scheduling software for clinics', $sections['What we do']);
        $this->assertStringContainsString('Industry: Healthcare', $sections['What we do']);
        $this->assertStringContainsString('- double bookings', $sections['Where we are today']);
        $this->assertStringContainsString('Team today: 3-10 people', $sections['Where we are today']);
        $this->assertSame(BrainMarkdown::MISSING, $sections['What makes us different'], 'unanswered sections carry the missing marker');

        $alignment = $this->store->read((string) $this->tenant->id, 'business/alignment.md');
        $this->assertStringContainsString('lose patients to no-shows', BrainMarkdown::section($alignment->content, 'Origin'));
        $this->assertStringContainsString('New paying clinics: 30 (owner: founder)', BrainMarkdown::section($alignment->content, '90-day target'));
        $this->assertSame(['care', 'speed'], $alignment->frontmatter['values']);

        $offer = $this->store->read((string) $this->tenant->id, 'offer/offer.md');
        $this->assertSame('Subscription, $99/month', $offer->frontmatter['pricing']);
        $this->assertSame(['Cut no-shows by 40%', 'Rated 4.9 on Capterra'], $offer->frontmatter['proof_points']);
        $this->assertSame([], $offer->frontmatter['links']);
        $this->assertStringContainsString('- Cut no-shows by 40%', BrainMarkdown::section($offer->content, 'Proof'));

        $voice = $this->store->read((string) $this->tenant->id, 'brand/voice.md');
        $this->assertSame('warm and professional', $voice->frontmatter['tone'], 'preferred_tone finally lands somewhere');
        $tone = BrainMarkdown::section($voice->content, 'Tone');
        $this->assertStringContainsString('Preferred tone: warm and professional', $tone);
        $this->assertStringContainsString('Warm, specific, and brief.', $tone, 'the crm.md Tone lines are carried over');

        $icp = $this->store->read((string) $this->tenant->id, 'customers/icp.md');
        $this->assertStringContainsString('2-5 doctor clinic', BrainMarkdown::section($icp->content, 'Who we sell to'));
        $this->assertStringContainsString('Solo practitioners', BrainMarkdown::section($icp->content, 'Who we do not sell to'));

        $objections = $this->store->read((string) $this->tenant->id, 'customers/objections.md');
        $answers = BrainMarkdown::section($objections->content, 'Objections and answers');
        $this->assertStringContainsString('We already use a paper calendar', $answers);
        $this->assertStringContainsString('objection-handling angles', $answers);

        $followUps = $this->store->read((string) $this->tenant->id, 'processes/follow-ups.md');
        $cadence = BrainMarkdown::section($followUps->content, 'Cadence');
        $this->assertStringContainsString('partner.invite after 0 day(s)', $cadence);
        $this->assertStringContainsString('Reply ownership: draft for me to approve', $cadence);

        $this->assertSame('confidential', $this->store->read((string) $this->tenant->id, 'finance/summary.md')->data_class);
        $this->assertSame(BrainMarkdown::MISSING, BrainMarkdown::section($this->store->read((string) $this->tenant->id, 'market/comparables.md')->content, 'Comparables'));

        // Idempotent: nothing changed, nothing rewritten.
        $this->assertSame([], $this->sync->syncAll((string) $this->tenant->id));
        $this->assertSame(1, $this->store->read((string) $this->tenant->id, 'business/profile.md')->version);
    }

    public function test_managed_blocks_are_replaced_while_human_prose_outside_survives(): void
    {
        $setup = $this->seedBusinessContext();
        $tenantId = (string) $this->tenant->id;
        $this->sync->syncAll($tenantId);

        $profile = $this->store->read($tenantId, 'business/profile.md');
        $edited = BrainMarkdown::upsertSection($profile->content, 'What makes us different', 'We answer the phone in one ring — nobody else does.');
        $edited = str_replace(
            '<!-- managed:start profile.what_we_do -->',
            "Human note above the block.\n\n<!-- managed:start profile.what_we_do -->",
            $edited,
        );
        $human = $this->store->write($tenantId, 'business/profile.md', $edited, $profile->frontmatter, 'human', (string) $this->admin->id, $profile->version);
        $this->assertSame(2, $human->version);
        $this->assertSame('human', $human->source);
        $this->assertFalse($human->managed, 'human prose outside the blocks → no longer wholly managed');

        $answers = $setup->interview_answers;
        $answers['core_offer']['answer'] = 'Front-desk automation for dental clinics';
        $setup->update(['interview_answers' => $answers]);

        $written = $this->sync->syncAll($tenantId);
        $this->assertContains('business/profile.md', $written);

        $after = $this->store->read($tenantId, 'business/profile.md');
        $this->assertSame(3, $after->version);
        $this->assertSame('projection', $after->source);
        $this->assertFalse($after->managed, 'the re-sync keeps the human prose, so the file stays partially managed');
        $sections = BrainMarkdown::sections($after->content);
        $this->assertStringContainsString('Front-desk automation for dental clinics', $sections['What we do']);
        $this->assertStringNotContainsString('Appointment scheduling software', $sections['What we do']);
        $this->assertStringContainsString('Human note above the block.', $sections['What we do'], 'prose outside the block survives');
        $this->assertSame('We answer the phone in one ring — nobody else does.', $sections['What makes us different'], 'human section untouched, marker gone');
        $this->assertSame(1, substr_count($after->content, 'managed:start profile.what_we_do'), 'blocks are replaced, not duplicated');
    }

    public function test_affiliate_frontmatter_equals_the_outreach_program_facts(): void
    {
        $tenantId = (string) $this->tenant->id;

        $this->sync->syncAll($tenantId);
        $this->assertNull($this->store->read($tenantId, 'programs/affiliate.md'), 'no outreach settings → no affiliate file');

        $this->seedOutreach();
        $this->sync->syncAll($tenantId);

        $file = $this->store->read($tenantId, 'programs/affiliate.md');
        $this->assertNotNull($file);
        $this->assertSame(app(OutreachSettings::class)->for($this->tenant->fresh())['program'], $file->frontmatter);
        $this->assertTrue($file->managed, 'the affiliate file is wholly projection-owned');
        $facts = BrainMarkdown::section($file->content, 'Facts');
        $this->assertStringContainsString('Commission: 30% for 12 months', $facts);
        $this->assertStringContainsString('Join: https://hannah.affonso.io/?group=grp1', $facts);
        $this->assertStringContainsString('Postal address: 1 Test Street, Harare', $facts);
    }

    public function test_people_user_is_written_for_the_primary_admin(): void
    {
        $this->seedBusinessContext();
        $tenantId = (string) $this->tenant->id;

        $this->sync->syncAll($tenantId);

        $user = $this->store->read($tenantId, 'people/user.md');
        $this->assertNotNull($user);
        $this->assertSame('personal', $user->data_class);
        $this->assertSame('Jason M', $user->frontmatter['name']);
        $this->assertSame($this->admin->email, $user->frontmatter['email']);
        $this->assertSame('admin', $user->frontmatter['role']);
        $this->assertSame(['Clinic Scheduler'], $user->frontmatter['businesses']);
        $this->assertSame('Africa/Harare', $user->frontmatter['timezone'], 'tenant timezone is the fallback');
        $this->assertSame('https://cal.com/jason/15min', $user->frontmatter['calendar_link']);
        $this->assertSame(['15-minute intro', '45-minute demo'], $user->frontmatter['meeting_types']);

        $sections = BrainMarkdown::sections($user->content);
        $this->assertStringContainsString('Jason M — Admin at Clinic Scheduler.', $sections['Who I am']);
        $this->assertStringContainsString('New paying clinics: 30', $sections['Goals']);
        $this->assertStringContainsString('Time zone: Africa/Harare', $sections['Working rhythm']);
        $this->assertStringContainsString('Preferred channel: whatsapp', $sections['How to reach me']);
        $this->assertStringContainsString('- 45-minute demo', $sections['Calendar and meetings']);
        $this->assertSame(BrainMarkdown::MISSING, $sections['Never say or offer'], 'unknown sections show as gaps');
        $this->assertSame(BrainMarkdown::MISSING, $sections['How I decide']);
    }

    public function test_record_answer_hook_projects_progressively(): void
    {
        // The funnel-setup events would also trigger BrainProjection → syncAll;
        // detach it so this test measures the recordAnswer() hook alone.
        config(['projections.projectors' => array_values(array_diff(
            (array) config('projections.projectors'),
            [BrainProjection::class],
        ))]);

        $tenantId = (string) $this->tenant->id;
        $funnel = app(FunnelSetupService::class);
        $setup = $funnel->beginInterview($funnel->getOrCreate($tenantId));

        $funnel->recordAnswer($setup, 'preferred_tone', 'formal and precise');

        $voice = $this->store->read($tenantId, 'brand/voice.md');
        $this->assertNotNull($voice, 'the answer landed in the brain without a full sync');
        $this->assertStringContainsString('Preferred tone: formal and precise', BrainMarkdown::section($voice->content, 'Tone'));
        $this->assertSame('formal and precise', $voice->frontmatter['tone']);
        $this->assertNull($this->store->read($tenantId, 'people/user.md'), 'only the files this question maps to are touched');

        $funnel->recordAnswer($setup->fresh(), 'core_offer', 'Appointment scheduling software for clinics');
        $this->assertStringContainsString('Appointment scheduling software', $this->store->read($tenantId, 'offer/offer.md')->content);
        $this->assertStringContainsString('Appointment scheduling software', $this->store->read($tenantId, 'business/profile.md')->content);
    }

    public function test_sync_if_stale_only_runs_when_a_source_changed(): void
    {
        $setup = $this->seedBusinessContext();
        $tenantId = (string) $this->tenant->id;

        $this->sync->syncAll($tenantId);
        $this->assertSame(1, $this->store->read($tenantId, 'offer/offer.md')->version);

        $this->sync->syncIfStale($tenantId);
        $this->assertSame(1, $this->store->read($tenantId, 'offer/offer.md')->version, 'nothing changed → no sync');

        Carbon::setTestNow(now()->addMinutes(2));
        $answers = $setup->interview_answers;
        $answers['pricing_model']['answer'] = 'Subscription, $149/month';
        $setup->update(['interview_answers' => $answers]);

        $this->sync->syncIfStale($tenantId);
        $offer = $this->store->read($tenantId, 'offer/offer.md');
        $this->assertSame(2, $offer->version);
        $this->assertSame('Subscription, $149/month', $offer->frontmatter['pricing']);
    }

    public function test_processes_and_published_sops_are_projected(): void
    {
        $tenantId = (string) $this->tenant->id;
        $system = BusinessSystem::create(['tenant_id' => $tenantId, 'function' => 'sales', 'name' => 'Lead handling', 'goal' => 'Every lead answered']);
        $process = BusinessProcess::create([
            'tenant_id' => $tenantId, 'system_id' => $system->id, 'name' => 'Lead intake', 'goal' => 'Qualify within an hour',
            'owner_type' => 'founder', 'effort_size' => 2, 'status' => 'founder_owned',
        ]);
        Sop::create([
            'tenant_id' => $tenantId, 'process_id' => $process->id, 'version' => 1, 'title' => 'Lead intake SOP', 'purpose' => 'Never lose a lead',
            'trigger' => 'A form submission arrives', 'steps' => ['Open the lead', ['title' => 'Call within 5 minutes', 'description' => 'Use the opener']],
            'quality_criteria' => ['Answered within an hour'], 'status' => 'published',
        ]);
        Sop::create(['tenant_id' => $tenantId, 'process_id' => $process->id, 'version' => 2, 'title' => 'Draft', 'steps' => ['Unpublished step'], 'status' => 'draft']);

        $written = $this->sync->syncAll($tenantId);

        $this->assertContains('processes/lead-intake.md', $written);
        $file = $this->store->read($tenantId, 'processes/lead-intake.md');
        $this->assertSame('Lead intake', $file->title);
        $this->assertSame((string) $process->id, $file->frontmatter['process_id']);
        $this->assertSame('founder', $file->frontmatter['owner_type']);
        $this->assertSame(1, $file->frontmatter['sop_version'], 'only the published SOP is projected');
        $steps = BrainMarkdown::section($file->content, 'Steps');
        $this->assertStringContainsString('1. Open the lead', $steps);
        $this->assertStringContainsString('2. Call within 5 minutes — Use the opener', $steps);
        $this->assertStringNotContainsString('Unpublished step', $steps);
        $this->assertStringContainsString('Lead handling (Sales)', BrainMarkdown::section($file->content, 'Owner and hand-offs'));
        $this->assertStringContainsString('Qualify within an hour', BrainMarkdown::section($file->content, 'Goal'));
    }

    public function test_brain_projection_syncs_on_business_events(): void
    {
        $this->seedBusinessContext();
        $tenantId = (string) $this->tenant->id;
        $this->assertNull($this->store->read($tenantId, 'business/profile.md'));

        app(EventStore::class)->append($tenantId, 'tenant', $tenantId, 'tenant.business_profile.updated', ['industry' => 'healthcare']);

        $this->assertNotNull($this->store->read($tenantId, 'business/profile.md'), 'the projection ran inside the event write');
        $this->assertGreaterThan(0, BrainFile::forTenant($tenantId)->count());
    }
}
