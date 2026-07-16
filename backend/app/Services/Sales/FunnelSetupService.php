<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\FunnelSetup;
use App\Models\SalesScript;
use App\Models\Tenant;
use App\Services\ApprovalEngine;
use App\Services\AtlasDiscoveryService;
use App\Services\EventStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Drives the sales-crm pack's funnel_setup pipeline:
 *   purchased -> interviewing -> script_drafted -> awaiting_approval
 *             -> approved -> live  (or -> rejected -> interviewing, revision loop)
 *
 * `funnel_setups.status` / `sales_scripts.status` are the source of truth for
 * current state (see note in SteEventMappingSeeder — pack chains only get
 * ste_transitions Markov counts, not a live per-tenant state row).
 */
class FunnelSetupService
{
    private const PACK_ID = 'sales-crm';

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly ApprovalEngine $approvalEngine,
        private readonly AtlasDiscoveryService $discovery,
    ) {}

    public function getOrCreate(string $tenantId): FunnelSetup
    {
        $setup = FunnelSetup::forTenant($tenantId)->where('pack_id', self::PACK_ID)->first();
        if ($setup) {
            return $setup;
        }

        $setup = FunnelSetup::create([
            'tenant_id' => $tenantId,
            'pack_id' => self::PACK_ID,
            'status' => 'purchased',
            'interview_answers' => [],
        ]);

        $this->eventStore->append(
            $tenantId,
            'funnel_setup',
            $setup->id,
            'pack.sales-crm.funnel_setup.purchased',
            ['funnel_setup_id' => $setup->id],
        );

        return $setup;
    }

    public function beginInterview(FunnelSetup $setup): FunnelSetup
    {
        if ($setup->status !== 'purchased') {
            return $setup;
        }

        $questions = $this->loadInterviewQuestions();
        $firstSection = $questions['sections'][0]['id'] ?? null;

        $setup->update(['status' => 'interviewing', 'current_section' => $firstSection]);

        $this->eventStore->append(
            $setup->tenant_id,
            'funnel_setup',
            $setup->id,
            'pack.sales-crm.funnel_setup.interview.started',
            ['funnel_setup_id' => $setup->id],
        );

        return $setup->refresh();
    }

    /**
     * @return array{done: bool, section?: array, question?: array, progress_pct: int}
     */
    public function nextQuestion(FunnelSetup $setup): array
    {
        $questions = $this->loadInterviewQuestions();
        $sections = $questions['sections'] ?? [];
        $answers = $setup->interview_answers ?? [];

        $totalQuestions = array_sum(array_map(fn ($s) => count($s['questions'] ?? []), $sections));
        $answeredCount = count($answers);

        foreach ($sections as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if (! array_key_exists($question['id'], $answers)) {
                    return [
                        'done' => false,
                        'section' => ['id' => $section['id'], 'title' => $section['title'] ?? $section['id']],
                        'question' => $question,
                        'progress_pct' => $totalQuestions > 0 ? (int) round(100 * $answeredCount / $totalQuestions) : 0,
                    ];
                }
            }
        }

        return ['done' => true, 'progress_pct' => 100];
    }

    /**
     * @param array<string, mixed> $context Extra fields absorbed by AtlasDiscoveryService (e.g. free-text for pain point mining).
     */
    public function recordAnswer(FunnelSetup $setup, string $questionId, string $answer): FunnelSetup
    {
        $questions = $this->loadInterviewQuestions();
        $questionDef = $this->findQuestion($questions, $questionId);

        $answers = $setup->interview_answers ?? [];
        $answers[$questionId] = [
            'question' => $questionDef['prompt'] ?? $questionId,
            'answer' => $answer,
            'answered_at' => now()->toIso8601String(),
        ];

        $currentSection = null;
        foreach ($questions['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $q) {
                if ($q['id'] === $questionId) {
                    $currentSection = $section['id'];
                }
            }
        }

        $setup->update(['interview_answers' => $answers, 'current_section' => $currentSection]);

        // Mirror generically-useful answers into the ambient business profile
        // so PackGrowthService recommendations keep learning across packs.
        $this->discovery->absorbAnswer($setup->tenant_id, $answer);

        return $setup->refresh();
    }

    public function draftScript(FunnelSetup $setup): SalesScript
    {
        $nextVersion = (int) (SalesScript::forTenant($setup->tenant_id)
            ->where('funnel_setup_id', $setup->id)
            ->max('version') ?? 0) + 1;

        $script = SalesScript::create([
            'tenant_id' => $setup->tenant_id,
            'funnel_setup_id' => $setup->id,
            'version' => $nextVersion,
            'status' => 'draft',
            'content' => $this->composeStarterScript($setup),
            'rationale' => 'Drafted from your discovery interview answers and best-practice conversion scripts for your lead type.',
            'created_by' => 'funnel_architect',
        ]);

        $setup->update(['status' => 'script_drafted', 'active_script_id' => $script->id]);

        $this->eventStore->append(
            $setup->tenant_id,
            'funnel_setup',
            $setup->id,
            'pack.sales-crm.funnel_setup.script.drafted',
            ['funnel_setup_id' => $setup->id, 'script_id' => $script->id, 'version' => $nextVersion],
        );

        // The funnel_architect agent (intelligence/agents/funnel_architect.py)
        // is dispatched on this same event (see agents/funnel-architect.yaml
        // trigger: pack.sales-crm.funnel_setup.script.drafted) to enrich this
        // starter draft with an LLM-adapted pass over brand voice/tone. This
        // deterministic version keeps Script Studio usable before that runs.

        return $script;
    }

    public function reviseScript(FunnelSetup $setup, SalesScript $script, array $sectionEdits): SalesScript
    {
        $content = $script->content ?? [];
        foreach ($sectionEdits as $channel => $sections) {
            $content[$channel] = array_merge($content[$channel] ?? [], $sections);
        }
        $script->update(['content' => $content]);

        return $script->refresh();
    }

    public function submitForApproval(FunnelSetup $setup, SalesScript $script, string $requestedByUserId): array
    {
        $script->update(['status' => 'submitted']);
        $setup->update(['status' => 'awaiting_approval']);

        $this->eventStore->append(
            $setup->tenant_id,
            'funnel_setup',
            $setup->id,
            'pack.sales-crm.funnel_setup.approval.requested',
            ['funnel_setup_id' => $setup->id, 'script_id' => $script->id],
        );

        $approval = $this->approvalEngine->createApproval(
            tenantId: $setup->tenant_id,
            requesterId: $this->funnelArchitectAgentId($setup->tenant_id) ?? $requestedByUserId,
            type: 'manual',
            resourceType: 'sales_script',
            resourceId: $script->id,
            reason: "Approve the drafted lead-to-sale sales script (v{$script->version}) so the funnel can go live.",
            context: ['funnel_setup_id' => $setup->id],
        );

        $setup->update(['approval_id' => $approval['id']]);

        return $approval;
    }

    /**
     * Called by ApprovalController::approve() when resource_type === 'sales_script'.
     */
    public function activateFromApproval(string $tenantId, string $scriptId): void
    {
        $script = SalesScript::forTenant($tenantId)->find($scriptId);
        if (! $script) {
            Log::warning('activateFromApproval: sales_script not found', ['script_id' => $scriptId]);

            return;
        }

        $setup = FunnelSetup::forTenant($tenantId)->find($script->funnel_setup_id);
        if (! $setup) {
            return;
        }

        $script->update(['status' => 'approved', 'approved_at' => now()]);
        $setup->update(['status' => 'approved']);

        $this->eventStore->append(
            $tenantId, 'funnel_setup', $setup->id,
            'pack.sales-crm.funnel_setup.approval.granted',
            ['funnel_setup_id' => $setup->id, 'script_id' => $script->id],
        );

        $this->activate($setup, $script);
    }

    public function rejectFromApproval(string $tenantId, string $scriptId, ?string $reason): void
    {
        $script = SalesScript::forTenant($tenantId)->find($scriptId);
        if (! $script) {
            return;
        }

        $setup = FunnelSetup::forTenant($tenantId)->find($script->funnel_setup_id);
        if (! $setup) {
            return;
        }

        $script->update(['status' => 'retired']);
        $setup->update(['status' => 'rejected']);

        $this->eventStore->append(
            $tenantId, 'funnel_setup', $setup->id,
            'pack.sales-crm.funnel_setup.approval.rejected',
            ['funnel_setup_id' => $setup->id, 'script_id' => $script->id, 'reason' => $reason],
        );
    }

    /**
     * Requests a new draft after a rejection or an owner-requested revision.
     */
    public function requestRevision(FunnelSetup $setup): FunnelSetup
    {
        $setup->update(['status' => 'interviewing']);

        $this->eventStore->append(
            $setup->tenant_id, 'funnel_setup', $setup->id,
            'pack.sales-crm.funnel_setup.script.revision_requested',
            ['funnel_setup_id' => $setup->id],
        );

        return $setup->refresh();
    }

    /**
     * Activates pack agents, seeds message templates (once the message_templates
     * table exists — see M3), registers the script as a business asset (once
     * business_assets exists — see M5), and flips the funnel live.
     */
    private function activate(FunnelSetup $setup, SalesScript $script): void
    {
        DB::table('agents')
            ->where('tenant_id', $setup->tenant_id)
            ->where('config->pack_id', self::PACK_ID)
            ->update(['status' => 'active', 'activated_at' => now(), 'updated_at' => now()]);

        if (Schema::hasTable('message_templates')) {
            app(MessageTemplateSeeder::class)->seedFromScript($setup->tenant_id, $script);
        }

        if (Schema::hasTable('business_assets')) {
            DB::table('business_assets')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $setup->tenant_id,
                'type' => 'sales_script',
                'name' => "Sales script v{$script->version}",
                'ref_type' => SalesScript::class,
                'ref_id' => $script->id,
                'version' => $script->version,
                'quarter' => now()->format('Y').'-Q'.ceil(now()->month / 3),
                'status' => 'active',
                'created_by' => 'funnel_architect',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $setup->update(['status' => 'live', 'went_live_at' => now()]);

        $this->eventStore->append(
            $setup->tenant_id, 'funnel_setup', $setup->id,
            'pack.sales-crm.funnel_setup.went_live',
            ['funnel_setup_id' => $setup->id, 'script_id' => $script->id],
        );

        // The funnel_architect agent's activate_funnel action (dispatched on
        // this same event per flows/funnel-go-live.dag.yaml) writes the
        // approved script into the tenant's MemoryGraph
        // (intelligence/core/memory_graph.py) so DynamicAgent conversation
        // handling can retrieve it as context — embeddings are generated
        // Python-side, not here.
    }

    /**
     * Deterministic starting script so Script Studio has something to show
     * and edit before the funnel_architect agent's LLM pass runs. Uses the
     * interview answers plus the pack's shared conversion-scripts.yaml priors.
     */
    private function composeStarterScript(FunnelSetup $setup): array
    {
        $answers = $setup->interview_answers ?? [];
        $offer = $answers['core_offer']['answer'] ?? 'what you offer';
        $proof = $answers['proof_points']['answer'] ?? null;
        $objection = $answers['common_objection']['answer'] ?? null;
        $tone = $answers['preferred_tone']['answer'] ?? 'warm and professional';

        $opener = "Thanks for reaching out about {$offer} — before I suggest anything, I'd love to understand what you're trying to solve.";
        $qualify = 'What\'s the main thing that made you look into this today?';
        $objections = $objection
            ? "A common concern is: \"{$objection}\". Here's how we address it: [tailor this to your specific proof/answer]."
            : 'Address the lead\'s top objection here once you\'ve told us about it.';
        $close = 'Based on what you\'ve shared, I think we can help. Want to grab 15 minutes this week?';
        $followups = array_values(array_filter([
            $proof ? "Quick note: {$proof}" : null,
            'Following up — still worth exploring this together?',
            'Last check-in — happy to pick this back up whenever works for you.',
        ]));

        $sections = [
            'opener' => $opener,
            'qualify' => $qualify,
            'objections' => $objections,
            'close' => $close,
            'followups' => $followups,
        ];

        return [
            'tone' => $tone,
            'email' => $sections,
            'whatsapp' => array_merge($sections, ['opener' => Str::limit($opener, 200)]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInterviewQuestions(): array
    {
        $path = storage_path('app/feature-packs/'.self::PACK_ID.'/interview/questions.yaml');

        if (! is_readable($path)) {
            // Fallback to the source tree for local dev before the pack has
            // been staged via spidernet:pack-install.
            $path = dirname(base_path()).'/packages/feature-packs/'.self::PACK_ID.'/interview/questions.yaml';
        }

        if (! is_readable($path)) {
            return ['sections' => []];
        }

        return Yaml::parseFile($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQuestion(array $questions, string $questionId): ?array
    {
        foreach ($questions['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if ($question['id'] === $questionId) {
                    return $question;
                }
            }
        }

        return null;
    }

    private function funnelArchitectAgentId(string $tenantId): ?string
    {
        // Must match FeaturePackInstaller::provisionPackAgents()'s slug
        // exactly: Str::slug() turns the hyphen in "sales-crm" into an
        // underscore, so the real stored slug is "sales_crm_funnel_architect",
        // not the literal "sales-crm_funnel_architect".
        $slug = Str::slug(self::PACK_ID, '_').'_'.Str::slug('funnel_architect', '_');

        return DB::table('agents')
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->value('id');
    }
}
