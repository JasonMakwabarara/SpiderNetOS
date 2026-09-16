<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Models\AgentRun;
use App\Models\BrainFile;
use App\Models\Event;
use App\Models\PackEntitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\Collaborators;
use App\Services\Skills\SkillRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixture for the runtime tests: a sales-crm-entitled tenant with an
 * admin, the runtime flags on (tools off — PR 1 runs on the core brain/
 * drafts tools alone), a faked inference plane that serves staged
 * completions in order, and a fixture Knowledge brain seeded through the
 * real BrainStore when Stream A's class is present (direct brain_files
 * rows otherwise).
 */
abstract class AgentsTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $admin;

    /** @var list<array<string, mixed>|string> served in order by the faked /generate */
    protected array $completions = [];

    protected int $modelStatus = 200;

    /** @var list<Request> */
    protected array $generateRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Acme Ops', 'slug' => 'acme-ops-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'automation_level' => 'assisted',
            'onboarding_completed_at' => now(), 'settings' => [],
        ]);

        PackEntitlement::create([
            'tenant_id' => $this->tenant->id, 'pack_id' => 'sales-crm', 'source' => 'granted',
            'provider' => 'manual', 'status' => 'active', 'purchased_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Ops', 'email' => 'ops@'.Str::lower(Str::random(8)).'.test', 'password' => bcrypt('pw'),
            'tenant_id' => $this->tenant->id, 'role' => 'admin', 'onboarding_completed_at' => now(),
        ]);

        config()->set('agents.runtime_enabled', true);
        config()->set('broadcasting.default', 'null');
        $this->flags(['agents.runtime' => 'on', 'agents.tools' => 'off', 'agents.tools.send' => 'off', 'agents.tools.irreversible' => 'off']);

        if (class_exists(SkillRegistry::class)) {
            SkillRegistry::flush();
        }

        // One fake for the whole test (first matching stub wins in Laravel).
        Http::fake([
            '*/generate' => function (Request $request) {
                $this->generateRequests[] = $request;
                if ($this->modelStatus !== 200) {
                    return Http::response('inference plane unavailable', $this->modelStatus);
                }
                $next = array_shift($this->completions);
                if ($next === null) {
                    return Http::response('no completion staged for this test', 500);
                }

                return Http::response([
                    'text' => is_string($next) ? $next : (string) json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'model' => 'deepseek-v4-flash', 'tokens_used' => 120, 'cost' => 0.0004, 'provider' => 'modelark',
                ]);
            },
        ]);
    }

    // ------------------------------------------------------------------ //
    //  helpers
    // ------------------------------------------------------------------ //

    /** @param array<string, string> $values */
    protected function flags(array $values): void
    {
        config()->set('features', array_merge((array) config('features'), $values));
        Cache::flush();
    }

    protected function api(): static
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    /** Stage the inference plane's completions, served in order. */
    protected function model(array|string ...$completions): void
    {
        $this->modelStatus = 200;
        $this->completions = array_values($completions);
    }

    protected function modelDown(int $status = 503): void
    {
        $this->modelStatus = $status;
    }

    /** @return array<string, mixed> */
    protected function defaultInputs(): array
    {
        return ['campaign' => 'Spring Launch', 'segment' => 'Ops leads at growing agencies', 'steps' => 3, 'variants' => 2, 'channel' => 'email'];
    }

    protected function startRun(array $inputs = [], ?string $triggerRef = null, string $slug = 'cold-email-drafting'): AgentRun
    {
        return app(AgentRunService::class)->start(
            (string) $this->tenant->id, $slug, $inputs ?: $this->defaultInputs(), AgentRun::TRIGGER_MANUAL, $triggerRef, (string) $this->admin->id,
        );
    }

    /**
     * A completion that satisfies the cold-email-drafting output schema and
     * the validator's fact checks (no figures, no links, no banned phrases).
     *
     * @return array<string, mixed>
     */
    protected function validSequenceCompletion(string $campaign = 'Spring Launch', string $segment = 'Ops leads at growing agencies'): array
    {
        return [
            'campaign' => $campaign,
            'segment' => $segment,
            'angle' => 'Open on the leads lost to slow replies, prove it with the design agency story, then ask for a short call.',
            'steps' => [
                [
                    'step' => 1, 'beat' => 'problem',
                    'subjects' => ['Leads are going cold in your inbox', 'The reply that never went out'],
                    'body' => '{{first_line}} Most agency ops leads we talk to lose good enquiries to slow follow-up, not to competitors. The Follow-up Engine drafts every reply in your voice so nothing slips while you run the work. Worth a quick look?',
                    'send_day' => 0, 'cta' => 'Worth a quick look this week?', 'personalisation_slot' => '{{first_line}}',
                ],
                [
                    'step' => 2, 'beat' => 'proof',
                    'subjects' => ['How a design agency doubled its booked calls', 'One quarter, twice the calls'],
                    'body' => 'A design agency about your size doubled its booked calls within a quarter of switching to our sequences, and their operator still answers the phone. Happy to walk you through what changed for them.',
                    'send_day' => 3, 'cta' => 'Shall I send the short walkthrough?',
                ],
                [
                    'step' => 3, 'beat' => 'close',
                    'subjects' => ['Closing the loop on follow-ups', 'Last note from me'],
                    'body' => 'I will stop here unless you tell me otherwise. If slow replies are costing you work this quarter, a short call is the fastest way to see whether the Follow-up Engine fits. If not, no hard feelings.',
                    'send_day' => 7, 'cta' => 'Pick a slot that suits you?',
                ],
            ],
        ];
    }

    /**
     * Fixture brain: every section cold-email-drafting requires, sized past
     * the manifest's min_chars. brand/voice.md is left out when $withVoice
     * is false so the run blocks with the manifest question.
     *
     * @return array<string, array<string, string>> path => heading => body
     */
    protected function brainSections(bool $withVoice = true): array
    {
        $sections = [
            'business/profile.md' => [
                'What we do' => 'Acme Ops is a workflow studio that builds and runs automated follow-up systems for small agencies, so every lead gets a timely, personal reply without the founder chasing it by hand.',
                'What makes us different' => 'We install the system inside the client\'s own inbox and CRM in a fortnight, with a named operator on call, instead of selling a generic tool and leaving the agency to configure it alone.',
            ],
            'offer/offer.md' => [
                'Products and services' => 'The Follow-up Engine: a done-for-you setup of automated follow-up sequences, reply triage and weekly reporting for agencies with a handful of staff, sold as a monthly retainer with a fortnight of onboarding.',
                'Pricing' => 'Monthly retainer in the mid three figures, with a one-off onboarding fee; custom quotes for larger teams.',
                'Proof' => 'A twelve-person design agency doubled its booked calls within one quarter of switching to our sequences, and three clients have renewed twice.',
            ],
            'customers/icp.md' => [
                'Who we sell to' => 'Operations leads and founders at growing service agencies with a handful of people, who win work by referral, lose leads to slow replies, and want a system rather than another hire. They already use a simple CRM and a shared inbox.',
                'Who we do not sell to' => 'Solo freelancers with no inbound flow, and enterprises with a dedicated revenue operations team.',
            ],
        ];

        if ($withVoice) {
            $sections['brand/voice.md'] = $this->voiceSections();
        }

        return $sections;
    }

    /** @return array<string, string> */
    protected function voiceSections(): array
    {
        return [
            'Tone' => 'Warm, plain-spoken and direct. Two adjectives: candid and generous. A brand we admire: Basecamp, for short sentences, no jargon and respect for the reader\'s time.',
            "Do and don't" => 'Do write like a person who has done the work. Don\'t use hype words, exclamation marks, or promise results we cannot show.',
        ];
    }

    protected function seedBrain(bool $withVoice = true): void
    {
        foreach ($this->brainSections($withVoice) as $path => $sections) {
            $this->writeBrainFile($path, $sections);
        }
    }

    /**
     * BrainStore::upsertSection when Stream A's class exists, else the raw
     * brain_files row — both worlds must produce the same file.
     *
     * @param  array<string, string>  $sections  heading => body
     */
    protected function writeBrainFile(string $path, array $sections): void
    {
        $store = Collaborators::brainStore();
        if ($store !== null) {
            foreach ($sections as $heading => $body) {
                $store->upsertSection((string) $this->tenant->id, $path, $heading, $body, 'human', null);
            }

            return;
        }

        $existing = BrainFile::forTenant((string) $this->tenant->id)->where('path', $path)->first();
        $content = $existing ? (string) $existing->content : '';
        foreach ($sections as $heading => $body) {
            $content = rtrim($content)."\n\n## {$heading}\n\n{$body}\n";
        }
        $content = ltrim($content);

        if ($existing) {
            $existing->forceFill(['content' => $content, 'content_hash' => hash('sha256', $content), 'version' => $existing->version + 1])->save();

            return;
        }

        BrainFile::create([
            'tenant_id' => $this->tenant->id, 'path' => $path, 'title' => Str::headline(basename($path, '.md')),
            'content' => $content, 'frontmatter' => [], 'source' => 'human', 'managed' => false,
            'data_class' => 'internal', 'version' => 1, 'content_hash' => hash('sha256', $content),
        ]);
    }

    /** @return Collection<int, object> approvals rows for the tenant */
    protected function approvals(string $resourceType, ?string $status = null): Collection
    {
        return DB::table('approvals')->where('tenant_id', $this->tenant->id)->where('resource_type', $resourceType)
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderBy('created_at')->get();
    }

    /** @return Collection<int, Event> */
    protected function events(string $type): Collection
    {
        return Event::forTenant((string) $this->tenant->id)->where('event_type', $type)->orderBy('occurred_at')->get();
    }

    /** @return array<string, mixed> */
    protected function approvalContext(object $approval): array
    {
        return (array) json_decode((string) $approval->context, true);
    }

    protected function approve(string $approvalId, bool $grant = true, ?string $reason = null): void
    {
        $this->api()
            ->postJson('/api/approvals/'.$approvalId.($grant ? '/approve' : '/reject'), array_filter(['reason' => $reason ?? ($grant ? null : 'Not our voice.')]))
            ->assertOk();
    }
}
