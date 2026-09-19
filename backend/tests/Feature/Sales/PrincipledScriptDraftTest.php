<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\SalesScript;
use App\Models\Tenant;
use App\Services\Sales\FunnelSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * C2: principle-derived conversion-script entries are blended into funnel
 * setup drafts, cited in sales_scripts.rationale, and the legacy
 * deterministic draft survives when no entry's context matches.
 *
 * Runs against sqlite :memory: — the whole flow is driven through the real
 * FunnelSetupService (the same service the /api/sales endpoints call), with
 * pack policies read from the source tree via the loader's repo fallback.
 */
class PrincipledScriptDraftTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWERS = [
        'origin_story' => 'Started to fix scheduling for clinics.',
        'mission' => 'Never leave a message unanswered for an hour.',
        'vision' => 'Every small clinic running on our platform.',
        'ninety_day_target' => '30 new paying clinics',
        'core_offer' => 'Appointment scheduling software for clinics',
        'pricing_model' => 'Subscription, $99/month',
        'proof_points' => 'Cut no-shows by 40%',
        'ideal_customer' => 'A 2-5 doctor clinic',
        'disqualifiers' => 'Solo practitioners',
        'common_objection' => 'We already use a paper calendar',
        'preferred_tone' => 'warm and professional',
        'response_ownership' => 'draft for me to approve',
        'team_size' => '3-10 people',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // A staged storage copy (possibly stale, from another test run's pack
        // install) would shadow the source-tree policies under test.
        File::deleteDirectory(storage_path('app/feature-packs/sales-crm'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/feature-packs/sales-crm'));
        parent::tearDown();
    }

    private function createTenant(): Tenant
    {
        return Tenant::create([
            'id' => Str::uuid(),
            'name' => 'Principled Co',
            'slug' => 'principled-'.Str::lower(Str::random(8)),
            'status' => 'active',
            'plan' => 'pro',
            'onboarding_completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, string>  $answers
     */
    private function draftFor(array $answers): SalesScript
    {
        $tenant = $this->createTenant();
        $service = app(FunnelSetupService::class);

        $setup = $service->getOrCreate($tenant->id);
        $setup = $service->beginInterview($setup);
        foreach ($answers as $questionId => $answer) {
            $setup = $service->recordAnswer($setup, $questionId, $answer);
        }

        return $service->draftScript($setup);
    }

    public function test_draft_blends_matching_principle_entries_and_cites_them_in_rationale(): void
    {
        $script = $this->draftFor(self::ANSWERS);

        $opener = $script->content['email']['opener'];

        // Deterministic offer-specific baseline is preserved (the LLM pass and
        // the pipeline test both depend on it staying first)...
        $this->assertStringContainsString('Appointment scheduling software', $opener);
        // ...and the matching cold-inbound principle entry is blended in after it.
        $this->assertStringContainsString('hand-offs that nobody owns', $opener);

        // Rationale cites the applied principle ids plus their short names.
        $this->assertStringContainsString('Applied sales principles:', $script->rationale);
        $this->assertStringContainsString('impulse-curve-management', $script->rationale);
        $this->assertStringContainsString('value-cost-gap', $script->rationale);
        $this->assertStringContainsString('Ride the excitement curve', $script->rationale);

        // WhatsApp channel got the same blend (its opener is trimmed to 200
        // chars for the channel, so check the shared close section).
        $this->assertStringContainsString('we part as friends', $script->content['whatsapp']['close'] ?? '');

        $this->assertDatabaseHas('event_log', [
            'tenant_id' => $script->tenant_id,
            'event_type' => 'pack.sales-crm.funnel_setup.script.drafted',
        ]);
    }

    public function test_price_objection_answer_pulls_objection_handling_entry(): void
    {
        $answers = self::ANSWERS;
        $answers['common_objection'] = 'It looks too expensive for our budget right now';

        $script = $this->draftFor($answers);

        $objections = $script->content['email']['objections'];
        $this->assertStringContainsString('Proven objection-handling angle', $objections);
        // The owner's own objection wording stays in the deterministic part.
        $this->assertStringContainsString('too expensive for our budget', $objections);
        $this->assertStringContainsString('objection-loop', $script->rationale);
        $this->assertStringContainsString('feel-felt-found', $script->rationale);
    }

    public function test_unmatchable_context_falls_back_to_legacy_deterministic_draft(): void
    {
        // Stage a storage policies file whose only entry can never match —
        // exercising the storage-path branch of the loader and the graceful
        // deterministic fallback in one go.
        $dir = storage_path('app/feature-packs/sales-crm/policies');
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/conversion-scripts.yaml', implode("\n", [
            'scripts:',
            '  - id: unreachable',
            "    context: \"lead.source == 'carrier_pigeon' && lead.warmth == 'volcanic'\"",
            '    opening: "PRINCIPLE_COPY_SHOULD_NOT_APPEAR"',
            '    follow_up: "PRINCIPLE_COPY_SHOULD_NOT_APPEAR"',
            '    closing: "PRINCIPLE_COPY_SHOULD_NOT_APPEAR"',
            '    principle_ids: [impulse-curve-management]',
            '  - id: malformed-context-is-skipped-not-fatal',
            '    context: "lead.source === ((( carrier pigeon"',
            '    opening: "PRINCIPLE_COPY_SHOULD_NOT_APPEAR"',
        ])."\n");

        $script = $this->draftFor(self::ANSWERS);

        $this->assertStringContainsString('Appointment scheduling software', $script->content['email']['opener']);
        $this->assertStringNotContainsString('PRINCIPLE_COPY_SHOULD_NOT_APPEAR', json_encode($script->content));
        $this->assertSame(
            'Drafted from your discovery interview answers and best-practice conversion scripts for your lead type.',
            $script->rationale,
        );
    }
}
