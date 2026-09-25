<?php

declare(strict_types=1);

namespace App\Services\Brain;

use App\Models\BrainFile;
use App\Models\BusinessProcess;
use App\Models\FunnelSetup;
use App\Models\SalesScript;
use App\Models\Sop;
use App\Models\Tenant;
use App\Models\TenantAlignmentProfile;
use App\Models\User;
use App\Services\Outreach\OutreachSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * Projects the business context that already lives in tables into the
 * Knowledge brain (plan D2): tenants, tenant_business_profiles,
 * tenant_alignment_profiles, funnel_setups.interview_answers (including the
 * `preferred_tone` answer nothing read before), sales_scripts,
 * business_processes/sops, OutreachSettings FACTS and the tenant's primary
 * user.
 *
 * Rules
 *   - Projections write ONLY inside `<!-- managed:start <key> -->` …
 *     `<!-- managed:end <key> -->` blocks. Human prose outside them survives
 *     every sync; a section that has no data yet carries `<!-- missing -->`
 *     so BrainGapAnalyzer can ask for it.
 *   - Structured facts go to frontmatter (projected keys win, human keys
 *     survive); prose goes in the body.
 *   - Writes are `source = 'projection'`; BrainStore marks `managed = true`
 *     only when the whole file is projection-owned.
 *   - No column stores the last sync time (brain_files has none), so the
 *     marker is a per-tenant cache entry with a fallback to the newest
 *     projection-sourced file. Losing the marker only costs one extra sync.
 */
final class BrainSyncService
{
    public const CHANGED_BY = BrainStore::CHANGED_BY_SYNC;

    private const PACK_ID = 'sales-crm';

    private const MARKER_TTL_SECONDS = 7 * 86400;

    /** @var array<string, bool> */
    private array $tables = [];

    public function __construct(
        private readonly BrainStore $store,
        private readonly BrainManifest $manifest,
        private readonly OutreachSettings $outreach,
    ) {}

    /**
     * Project everything for one tenant.
     *
     * @return list<string> paths that received a new version
     */
    public function syncAll(string $tenantId): array
    {
        $context = $this->context($tenantId);
        if ($context === null) {
            return [];
        }

        $written = [];
        foreach ($this->projectors() as $name => $projector) {
            try {
                foreach ($projector['run']($context) as $path) {
                    $written[] = $path;
                }
            } catch (\Throwable $e) {
                Log::warning('brain.sync.projector_failed', ['tenant_id' => $tenantId, 'projector' => $name, 'error' => $e->getMessage()]);
            }
        }

        $this->markSynced($tenantId);

        return array_values(array_unique($written));
    }

    /** Sync only when a source table changed since the last sync. */
    public function syncIfStale(string $tenantId): void
    {
        $last = $this->lastSyncedAt($tenantId);
        if ($last !== null) {
            $sources = $this->sourcesUpdatedAt($tenantId);
            if ($sources === null || $sources->lte($last)) {
                return;
            }
        }

        $this->syncAll($tenantId);
    }

    /**
     * Progressive projection from FunnelSetupService::recordAnswer(): only the
     * files the manifest maps this question to are re-rendered.
     */
    public function projectInterviewAnswer(FunnelSetup $setup, string $questionId): void
    {
        $paths = array_values(array_unique(array_column($this->manifest->interviewTargets($questionId), 'path')));
        if ($paths === []) {
            return;
        }

        $context = $this->context((string) $setup->tenant_id, $setup);
        if ($context === null) {
            return;
        }

        foreach ($this->projectors() as $name => $projector) {
            if (array_intersect($projector['paths'], $paths) === []) {
                continue;
            }
            try {
                $projector['run']($context);
            } catch (\Throwable $e) {
                Log::warning('brain.sync.projector_failed', ['tenant_id' => $setup->tenant_id, 'projector' => $name, 'question_id' => $questionId, 'error' => $e->getMessage()]);
            }
        }
    }

    public function lastSyncedAt(string $tenantId): ?CarbonInterface
    {
        try {
            $marker = Cache::get(self::markerKey($tenantId));
            if (is_string($marker) && $marker !== '') {
                return Carbon::parse($marker);
            }
        } catch (\Throwable) {
            // cache unavailable — fall through to the database
        }

        $newest = BrainFile::forTenant($tenantId)->where('source', BrainFile::SOURCE_PROJECTION)->max('updated_at');

        return self::toCarbon($newest);
    }

    /** Newest updated_at across every table a projection reads. */
    public function sourcesUpdatedAt(string $tenantId): ?CarbonInterface
    {
        $stamps = [Tenant::where('id', $tenantId)->value('updated_at')];
        foreach (['tenant_business_profiles', 'tenant_alignment_profiles', 'funnel_setups', 'sales_scripts', 'business_processes', 'sops', 'users'] as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }
            try {
                $stamps[] = DB::table($table)->where('tenant_id', $tenantId)->max('updated_at');
            } catch (\Throwable) {
                continue;
            }
        }

        $max = null;
        foreach ($stamps as $stamp) {
            $at = self::toCarbon($stamp);
            if ($at !== null && ($max === null || $at->gt($max))) {
                $max = $at;
            }
        }

        return $max;
    }

    // ------------------------------------------------------------ projectors

    /** @return array<string, array{paths: list<string>, run: callable(array<string, mixed>): list<string>}> */
    private function projectors(): array
    {
        return [
            'profile' => ['paths' => ['business/profile.md'], 'run' => fn (array $c) => $this->projectProfile($c)],
            'alignment' => ['paths' => ['business/alignment.md'], 'run' => fn (array $c) => $this->projectAlignment($c)],
            'offer' => ['paths' => ['offer/offer.md'], 'run' => fn (array $c) => $this->projectOffer($c)],
            'icp' => ['paths' => ['customers/icp.md'], 'run' => fn (array $c) => $this->projectIcp($c)],
            'objections' => ['paths' => ['customers/objections.md'], 'run' => fn (array $c) => $this->projectObjections($c)],
            'voice' => ['paths' => ['brand/voice.md'], 'run' => fn (array $c) => $this->projectVoice($c)],
            'processes' => ['paths' => ['processes/<slug>.md'], 'run' => fn (array $c) => $this->projectProcesses($c)],
            'follow_ups' => ['paths' => ['processes/follow-ups.md'], 'run' => fn (array $c) => $this->projectFollowUps($c)],
            'affiliate' => ['paths' => ['programs/affiliate.md'], 'run' => fn (array $c) => $this->projectAffiliate($c)],
            'user' => ['paths' => ['people/user.md'], 'run' => fn (array $c) => $this->projectUser($c)],
            'templates' => ['paths' => ['finance/summary.md', 'market/comparables.md'], 'run' => fn (array $c) => $this->projectTemplates($c)],
        ];
    }

    /** @param array<string, mixed> $c */
    private function projectProfile(array $c): array
    {
        $p = $c['profile'];
        $a = $c['answers'];
        /** @var Tenant $tenant */
        $tenant = $c['tenant'];

        $frontmatter = [
            'name' => $tenant->name,
            'website' => $tenant->domain ?: null,
            'industry' => $p['industry'] ?? null,
            'employee_count_band' => $p['employee_count_band'] ?? null,
            'country' => $p['country'] ?? null,
            'region' => $p['region'] ?? null,
        ];
        if ($c['outreach'] !== null) {
            $frontmatter['legal_operator'] = $c['outreach']['program']['operator_legal_name'] ?? null;
        }

        $painPoints = self::decodeList($p['pain_points'] ?? null);

        return $this->project($c['tenant_id'], 'business/profile.md', $frontmatter, [
            ['section' => 'What we do', 'key' => 'profile.what_we_do', 'body' => self::lines([
                $a['core_offer'] ?? null,
                ! empty($p['industry']) ? 'Industry: '.self::humanizeValue((string) $p['industry']) : null,
            ])],
            ['section' => 'Where we are today', 'key' => 'profile.today', 'body' => self::lines([
                ! empty($p['employee_count_band']) ? 'Team size band: '.$p['employee_count_band'] : null,
                ! empty($a['team_size']) ? 'Team today: '.$a['team_size'] : null,
                ! empty($p['country']) ? 'Based in: '.$p['country'].(! empty($p['region']) ? ' ('.$p['region'].')' : '') : null,
                ! empty($p['biggest_time_drain']) ? 'Biggest time drain: '.$p['biggest_time_drain'] : null,
                $painPoints !== [] ? "Pain points:\n".self::bullets($painPoints) : null,
            ])],
        ]);
    }

    /** @param array<string, mixed> $c */
    private function projectAlignment(array $c): array
    {
        /** @var TenantAlignmentProfile|null $al */
        $al = $c['alignment'];
        $a = $c['answers'];

        $frontmatter = [];
        if ($al !== null) {
            $frontmatter = [
                'values' => array_values((array) ($al->values ?? [])),
                'three_year_targets' => array_values((array) ($al->three_year_targets ?? [])),
                'one_year_targets' => array_values((array) ($al->one_year_targets ?? [])),
                'ninety_day_targets' => array_values((array) ($al->ninety_day_targets ?? [])),
                'cycle_started_at' => $al->current_cycle_started_at?->toDateString(),
            ];
        }

        return $this->project($c['tenant_id'], 'business/alignment.md', $frontmatter, [
            ['section' => 'Origin', 'key' => 'alignment.origin', 'body' => self::first($al?->origin_story, $a['origin_story'] ?? null)],
            ['section' => 'Mission', 'key' => 'alignment.mission', 'body' => self::first($al?->mission, $a['mission'] ?? null)],
            ['section' => 'Vision', 'key' => 'alignment.vision', 'body' => self::first($al?->vision, $a['vision'] ?? null)],
            ['section' => '90-day target', 'key' => 'alignment.ninety_day', 'body' => self::first(
                self::targetsText((array) ($al?->ninety_day_targets ?? [])),
                $a['ninety_day_target'] ?? null,
            )],
        ]);
    }

    /** @param array<string, mixed> $c */
    private function projectOffer(array $c): array
    {
        $a = $c['answers'];
        /** @var SalesScript|null $script */
        $script = $c['script'];
        $proof = self::splitList($a['proof_points'] ?? null);

        $frontmatter = [
            'products' => $a['core_offer'] ?? null,
            'pricing' => $a['pricing_model'] ?? null,
            'proof_points' => $proof,
        ];

        $blocks = [
            ['section' => 'Products and services', 'key' => 'offer.products', 'body' => $a['core_offer'] ?? null],
            ['section' => 'Pricing', 'key' => 'offer.pricing', 'body' => $a['pricing_model'] ?? null],
            ['section' => 'Proof', 'key' => 'offer.proof', 'body' => $proof !== [] ? self::bullets($proof) : null],
        ];

        $scriptText = null;
        if ($script !== null) {
            $content = (array) ($script->content ?? []);
            $email = (array) ($content['email'] ?? []);
            $scriptText = self::lines([
                "Active sales script v{$script->version} ({$script->status}).",
                ! empty($content['tone']) ? 'Tone: '.$content['tone'] : null,
                ! empty($email['opener']) ? "Opener:\n".$email['opener'] : null,
                ! empty($email['close']) ? "Close:\n".$email['close'] : null,
            ]);
        }
        $blocks[] = ['section' => 'Sales script', 'key' => 'offer.script', 'body' => $scriptText];

        return $this->project($c['tenant_id'], 'offer/offer.md', $frontmatter, $blocks, ['Sales script'], ['links' => []]);
    }

    /** @param array<string, mixed> $c */
    private function projectIcp(array $c): array
    {
        $a = $c['answers'];

        return $this->project($c['tenant_id'], 'customers/icp.md', [], [
            ['section' => 'Who we sell to', 'key' => 'icp.who', 'body' => $a['ideal_customer'] ?? null],
            ['section' => 'Who we do not sell to', 'key' => 'icp.disqualifiers', 'body' => $a['disqualifiers'] ?? null],
        ]);
    }

    /** @param array<string, mixed> $c */
    private function projectObjections(array $c): array
    {
        $a = $c['answers'];

        $body = self::lines([
            ! empty($a['common_objection']) ? 'Most common objection: '.$a['common_objection'] : null,
            $this->objectionPriors(),
        ]);

        return $this->project($c['tenant_id'], 'customers/objections.md', [], [
            ['section' => 'Objections and answers', 'key' => 'objections.answers', 'body' => $body],
        ]);
    }

    /** @param array<string, mixed> $c */
    private function projectVoice(array $c): array
    {
        $a = $c['answers'];
        /** @var Tenant $tenant */
        $tenant = $c['tenant'];

        $houseStyle = $this->packTone();
        $tone = self::lines([
            ! empty($a['preferred_tone']) ? 'Preferred tone: '.$a['preferred_tone'] : null,
            $houseStyle !== null ? 'House style (sales-crm pack): '.$houseStyle : null,
        ]);

        return $this->project($c['tenant_id'], 'brand/voice.md', [
            'brand' => $tenant->name,
            'tone' => $a['preferred_tone'] ?? null,
        ], [
            ['section' => 'Tone', 'key' => 'voice.tone', 'body' => $tone],
        ]);
    }

    /** @param array<string, mixed> $c */
    private function projectProcesses(array $c): array
    {
        if (! $this->hasTable('business_processes')) {
            return [];
        }

        $written = [];
        $slugs = [];
        $processes = BusinessProcess::where('tenant_id', $c['tenant_id'])->with('system')->orderBy('position')->orderBy('created_at')->get();

        foreach ($processes as $process) {
            $slug = BrainMarkdown::slug((string) $process->name);
            if (isset($slugs[$slug])) {
                $slug .= '-'.substr((string) $process->id, 0, 8);
            }
            $slugs[$slug] = true;

            $sop = $this->hasTable('sops')
                ? Sop::where('tenant_id', $c['tenant_id'])->where('process_id', $process->id)->where('status', 'published')->orderByDesc('version')->first()
                : null;

            $system = $process->system;
            $frontmatter = [
                'process_id' => (string) $process->id,
                'system' => $system?->name,
                'function' => $system?->function,
                'owner_type' => $process->owner_type,
                'owner_ref' => $process->owner_user_id ?? $process->owner_agent_id,
                'skill_slug' => null,
                'automation_level' => $process->status,
                'schedule_cron' => $process->schedule_cron,
                'sop_version' => $sop?->version,
            ];

            $steps = $sop !== null ? self::stepsText((array) ($sop->steps ?? [])) : null;
            $stepsBody = $sop !== null ? self::lines([
                ! empty($sop->purpose) ? 'Purpose: '.$sop->purpose : null,
                ! empty($sop->trigger) ? 'Trigger: '.$sop->trigger : null,
                $steps,
                ($tools = self::decodeList($sop->tools)) !== [] ? 'Tools: '.implode(', ', $tools) : null,
                ($criteria = self::decodeList($sop->quality_criteria)) !== [] ? "Quality criteria:\n".self::bullets($criteria) : null,
            ]) : null;

            $owner = self::lines([
                'Owner: '.self::humanizeValue((string) $process->owner_type).($process->owner_user_id ? ' (user '.$process->owner_user_id.')' : ($process->owner_agent_id ? ' (agent '.$process->owner_agent_id.')' : '')),
                $system !== null ? 'System: '.$system->name.' ('.self::humanizeValue((string) $system->function).')' : null,
                'Status: '.self::humanizeValue((string) $process->status).($process->needs_attention ? ' — needs attention' : ''),
            ]);

            foreach ($this->project(
                $c['tenant_id'],
                'processes/'.$slug.'.md',
                $frontmatter,
                [
                    ['section' => 'Goal', 'key' => 'process.goal', 'body' => $process->goal ?: null],
                    ['section' => 'Steps', 'key' => 'process.steps', 'body' => $stepsBody],
                    ['section' => 'Owner and hand-offs', 'key' => 'process.owner', 'body' => $owner],
                ],
                ['Goal'],
                [],
                (string) $process->name,
            ) as $path) {
                $written[] = $path;
            }
        }

        return $written;
    }

    /** @param array<string, mixed> $c */
    private function projectFollowUps(array $c): array
    {
        $a = $c['answers'];
        $lines = [];

        if ($c['outreach'] !== null) {
            $steps = [];
            foreach ((array) ($c['outreach']['sequence'] ?? []) as $step) {
                if (! is_array($step)) {
                    continue;
                }
                $steps[] = sprintf('Step %s: %s after %s day(s)', $step['step'] ?? '?', $step['template'] ?? 'message', $step['wait_days'] ?? 0);
            }
            if ($steps !== []) {
                $lines[] = "Partner outreach sequence:\n".self::bullets($steps);
            }
            $replies = (array) ($c['outreach']['replies'] ?? []);
            if (! empty($replies['mode'])) {
                $lines[] = 'Reply mode: '.($replies['mode'] === 'auto' ? 'send automatically' : 'draft for approval');
            }
        }

        $timing = $this->loadPackYaml('policies/followup-timing.yaml');
        $variants = array_values(array_filter((array) ($timing['variants'] ?? []), 'is_array'));
        if ($variants !== []) {
            usort($variants, static fn (array $x, array $y) => ((float) ($y['success_rate'] ?? 0)) <=> ((float) ($x['success_rate'] ?? 0)));
            $best = $variants[0];
            $lines[] = sprintf(
                'Reply timing prior: %s (%s) — %d%% success rate.',
                $best['description'] ?? $best['id'] ?? 'immediate',
                $best['timing'] ?? '',
                (int) round(100 * (float) ($best['success_rate'] ?? 0)),
            );
        }

        if (! empty($a['response_ownership'])) {
            $lines[] = 'Reply ownership: '.$a['response_ownership'];
        }

        return $this->project($c['tenant_id'], 'processes/follow-ups.md', [], [
            ['section' => 'Cadence', 'key' => 'follow_ups.cadence', 'body' => self::lines($lines)],
        ]);
    }

    /** @param array<string, mixed> $c */
    private function projectAffiliate(array $c): array
    {
        if ($c['outreach'] === null) {
            return [];
        }
        // The frontmatter is the program facts verbatim, scalars normalised so
        // the projection reads identically whether the column is json or jsonb.
        $program = BrainMarkdown::normalizeScalars((array) ($c['outreach']['program'] ?? []));

        $facts = self::lines([
            ! empty($program['brand']) ? 'Brand: '.$program['brand'] : null,
            isset($program['commission_pct']) ? sprintf('Commission: %s%% for %s months', $program['commission_pct'], $program['months'] ?? '?') : null,
            isset($program['cookie_days']) ? 'Cookie window: '.$program['cookie_days'].' days' : null,
            isset($program['min_payout_usd']) ? sprintf('Minimum payout: $%s (held %s days)', $program['min_payout_usd'], $program['hold_days'] ?? '?') : null,
            ! empty($program['terms_url']) ? 'Terms: '.$program['terms_url'] : null,
            ! empty($program['join_url']) ? 'Join: '.$program['join_url'] : null,
            ! empty($program['portal_name']) ? 'Portal: '.$program['portal_name'] : null,
            ! empty($program['operator_legal_name']) ? 'Operator: '.$program['operator_legal_name'] : null,
            ! empty($program['postal_address']) ? 'Postal address: '.$program['postal_address'] : null,
            ($exclusions = self::decodeList($program['exclusions'] ?? null)) !== [] ? 'Excluded: '.implode(', ', $exclusions) : null,
        ]);

        return $this->project($c['tenant_id'], 'programs/affiliate.md', $program, [
            ['section' => 'Facts', 'key' => 'affiliate.facts', 'body' => $facts],
        ]);
    }

    /** @param array<string, mixed> $c */
    private function projectUser(array $c): array
    {
        /** @var User|null $user */
        $user = $c['user'];
        if ($user === null) {
            return [];
        }
        /** @var Tenant $tenant */
        $tenant = $c['tenant'];
        /** @var TenantAlignmentProfile|null $al */
        $al = $c['alignment'];
        $a = $c['answers'];
        $prefs = (array) ($user->preferences ?? []);

        $timezone = $prefs['timezone'] ?? ($c['settings']['timezone'] ?? null);
        $quietHours = $prefs['quiet_hours'] ?? null;
        $channel = $prefs['preferred_channel'] ?? null;
        $calendar = $prefs['calendar_link'] ?? null;
        $meetingTypes = self::decodeList($prefs['meeting_types'] ?? null);

        $frontmatter = [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'businesses' => [$tenant->name],
            'timezone' => $timezone,
            'calendar_link' => $calendar,
            'preferred_channel' => $channel,
            'quiet_hours' => $quietHours,
            'meeting_types' => $meetingTypes,
        ];

        $blocks = [
            ['section' => 'Who I am', 'key' => 'user.who', 'body' => self::lines([
                sprintf('%s — %s at %s.', $user->name, self::humanizeValue((string) $user->role), $tenant->name),
                'Email: '.$user->email,
            ])],
            ['section' => 'Goals', 'key' => 'user.goals', 'body' => self::first(
                self::targetsText((array) ($al?->ninety_day_targets ?? [])),
                ! empty($a['ninety_day_target']) ? '90-day target: '.$a['ninety_day_target'] : null,
            )],
            ['section' => 'Working rhythm', 'key' => 'user.rhythm', 'body' => self::lines([
                $timezone ? 'Time zone: '.$timezone : null,
                $quietHours ? 'Quiet hours: '.self::scalarOrJson($quietHours) : null,
            ])],
            ['section' => 'How to reach me', 'key' => 'user.reach', 'body' => self::lines([
                $channel ? 'Preferred channel: '.$channel : null,
                ! empty($a['response_ownership']) ? 'Replies: '.$a['response_ownership'] : null,
            ])],
            ['section' => 'Calendar and meetings', 'key' => 'user.calendar', 'body' => self::lines([
                $calendar ? 'Booking link: '.$calendar : null,
                $meetingTypes !== [] ? "Meeting types:\n".self::bullets($meetingTypes) : null,
            ])],
        ];

        return $this->project($c['tenant_id'], 'people/user.md', $frontmatter, $blocks, [], [], $user->name, true);
    }

    /** Headings-only templates so the gaps show; never overwritten once present. */
    private function projectTemplates(array $c): array
    {
        $written = [];
        $templates = [
            'finance/summary.md' => ['base_currency' => null, 'cash' => null, 'runway_weeks' => null, 'mrr' => null, 'model_version' => null],
            'market/comparables.md' => [],
        ];
        foreach ($templates as $path => $frontmatter) {
            if ($this->store->read($c['tenant_id'], $path) !== null) {
                continue;
            }
            foreach ($this->project($c['tenant_id'], $path, $frontmatter, [], [], [], null, true) as $writtenPath) {
                $written[] = $writtenPath;
            }
        }

        return $written;
    }

    // ------------------------------------------------------------ rendering

    /**
     * Render projected blocks into a file: existing managed blocks are
     * replaced in place, new blocks are appended to their section, human
     * prose outside the blocks is untouched, empty sections carry the
     * `<!-- missing -->` marker.
     *
     * @param  array<string, mixed>  $frontmatter  projected keys (win over existing)
     * @param  list<array{section: string, key: string, body: ?string}>  $blocks
     * @param  list<string>  $extraSections  sections beyond the manifest's, in order
     * @param  array<string, mixed>  $defaults  frontmatter keys only set when absent
     * @return list<string> the path when a new version was written
     */
    private function project(
        string $tenantId,
        string $path,
        array $frontmatter,
        array $blocks,
        array $extraSections = [],
        array $defaults = [],
        ?string $title = null,
        bool $createEvenIfEmpty = false,
    ): array {
        $existing = $this->store->read($tenantId, $path);

        if ($existing === null && ! $createEvenIfEmpty) {
            $hasData = false;
            foreach ($blocks as $block) {
                if ($block['body'] !== null && trim($block['body']) !== '') {
                    $hasData = true;
                    break;
                }
            }
            if (! $hasData) {
                return [];
            }
        }

        $content = $existing?->content ?? $this->template($path, $extraSections, $title);

        foreach ($blocks as $block) {
            $inner = $block['body'] !== null ? trim($block['body']) : '';
            if (BrainMarkdown::hasManagedBlock($content, $block['key'])) {
                $content = BrainMarkdown::replaceManagedBlock($content, $block['key'], $inner);
            } elseif ($inner !== '') {
                $content = BrainMarkdown::appendToSection($content, $block['section'], BrainMarkdown::managedBlock($block['key'], $inner));
            }
        }

        $content = $this->normalizeMissingMarkers($content, $path, $extraSections);

        $merged = array_replace($defaults, (array) ($existing?->frontmatter ?? []), $frontmatter);

        $before = $existing !== null ? (int) $existing->version : 0;
        $file = $this->store->write(
            $tenantId,
            $path,
            $content,
            $merged,
            BrainFile::SOURCE_PROJECTION,
            self::CHANGED_BY,
            null,
            null,
            'Projected from business data',
        );

        return (int) $file->version !== $before ? [$path] : [];
    }

    /** @param list<string> $extraSections */
    private function template(string $path, array $extraSections = [], ?string $title = null): string
    {
        $lines = ['# '.($title ?? $this->manifest->title($path)), ''];
        foreach (array_merge(array_keys($this->manifest->sections($path)), $extraSections) as $name) {
            $lines[] = '## '.$name;
            $lines[] = '';
            $lines[] = BrainMarkdown::MISSING;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Keep exactly one `<!-- missing -->` marker in every empty declared
     * section and none in a section that has prose.
     *
     * @param  list<string>  $extraSections
     */
    private function normalizeMissingMarkers(string $content, string $path, array $extraSections): string
    {
        $declared = array_merge(array_keys($this->manifest->sections($path)), $extraSections);
        $present = BrainMarkdown::sections($content);

        foreach ($declared as $name) {
            $actual = null;
            foreach (array_keys($present) as $heading) {
                if (strcasecmp((string) $heading, $name) === 0) {
                    $actual = (string) $heading;
                    break;
                }
            }
            if ($actual === null) {
                continue;
            }
            $body = $present[$actual];
            $hasMarker = str_contains($body, BrainMarkdown::MISSING);
            $hasProse = BrainMarkdown::proseLength($body) > 0;

            if ($hasProse && $hasMarker) {
                $content = BrainMarkdown::upsertSection($content, $actual, trim(str_replace(BrainMarkdown::MISSING, '', $body)));
            } elseif (! $hasProse && ! $hasMarker) {
                $content = BrainMarkdown::upsertSection($content, $actual, trim($body) === '' ? BrainMarkdown::MISSING : trim($body)."\n\n".BrainMarkdown::MISSING);
            }
        }

        return $content;
    }

    // -------------------------------------------------------------- context

    /**
     * @return array<string, mixed>|null
     */
    private function context(string $tenantId, ?FunnelSetup $setup = null): ?array
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }
        $settings = (array) ($tenant->settings ?? []);

        $profile = $this->hasTable('tenant_business_profiles')
            ? (array) (DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first() ?? [])
            : [];

        $alignment = $this->hasTable('tenant_alignment_profiles') ? TenantAlignmentProfile::find($tenantId) : null;

        if ($setup === null && $this->hasTable('funnel_setups')) {
            $setup = FunnelSetup::forTenant($tenantId)->where('pack_id', self::PACK_ID)->first();
        }

        $answers = [];
        foreach ((array) ($setup?->interview_answers ?? []) as $id => $entry) {
            $text = is_array($entry) ? ($entry['answer'] ?? null) : $entry;
            if (is_string($text) && trim($text) !== '') {
                $answers[(string) $id] = trim($text);
            }
        }

        $script = null;
        if ($setup !== null && $this->hasTable('sales_scripts')) {
            if ($setup->active_script_id) {
                $script = SalesScript::forTenant($tenantId)->find($setup->active_script_id);
            }
            $script ??= SalesScript::forTenant($tenantId)
                ->where('funnel_setup_id', $setup->id)
                ->whereIn('status', ['approved', 'live'])
                ->orderByDesc('version')
                ->first();
        }

        $hasOutreach = ! empty($settings[OutreachSettings::KEY] ?? null);

        return [
            'tenant_id' => $tenantId,
            'tenant' => $tenant,
            'settings' => $settings,
            'profile' => $profile,
            'alignment' => $alignment,
            'setup' => $setup,
            'answers' => $answers,
            'script' => $script,
            'user' => $this->primaryUser($tenantId),
            'outreach' => $hasOutreach ? $this->outreach->for($tenant) : null,
        ];
    }

    private function primaryUser(string $tenantId): ?User
    {
        return User::where('tenant_id', $tenantId)->whereIn('role', ['admin', 'super_admin'])->orderBy('created_at')->first()
            ?? User::where('tenant_id', $tenantId)->orderBy('created_at')->first();
    }

    private function markSynced(string $tenantId): void
    {
        try {
            Cache::put(self::markerKey($tenantId), Carbon::now()->toIso8601String(), self::MARKER_TTL_SECONDS);
        } catch (\Throwable) {
            // cache unavailable — the projection-file fallback in lastSyncedAt() still works
        }
    }

    public static function markerKey(string $tenantId): string
    {
        return 'brain:synced_at:'.$tenantId;
    }

    private function hasTable(string $table): bool
    {
        if (! array_key_exists($table, $this->tables)) {
            try {
                $this->tables[$table] = Schema::hasTable($table);
            } catch (\Throwable) {
                $this->tables[$table] = false;
            }
        }

        return $this->tables[$table];
    }

    // ------------------------------------------------------------ pack files

    /** Objection-handling priors from the sales-crm pack's conversion scripts. */
    private function objectionPriors(): ?string
    {
        $scripts = $this->loadPackYaml('policies/conversion-scripts.yaml');
        $blocks = [];
        foreach ((array) ($scripts['scripts'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $context = (string) ($entry['context'] ?? '');
            if (! str_contains($context, 'lead.objection') && ! str_contains($context, 'objection')) {
                continue;
            }
            $lines = array_values(array_filter([
                ! empty($entry['opening']) ? 'Opening: '.trim((string) $entry['opening']) : null,
                ! empty($entry['follow_up']) ? 'Follow-up: '.trim((string) $entry['follow_up']) : null,
                ! empty($entry['closing']) ? 'Closing: '.trim((string) $entry['closing']) : null,
            ]));
            if ($lines === []) {
                continue;
            }
            $blocks[] = '**'.($entry['id'] ?? 'objection').'**'."\n".implode("\n", $lines);
        }

        return $blocks === [] ? null : "Proven objection-handling angles (sales-crm pack):\n\n".implode("\n\n", $blocks);
    }

    /** The `## Tone` paragraph of the sales-crm CRM agent prompt. */
    private function packTone(): ?string
    {
        $prompt = $this->loadPackText('prompts/crm.md');
        if ($prompt === null) {
            return null;
        }
        $tone = BrainMarkdown::section($prompt, 'Tone');

        return $tone !== null && trim($tone) !== '' ? trim($tone) : null;
    }

    /** @return array<string, mixed> */
    private function loadPackYaml(string $relative): array
    {
        $text = $this->loadPackText($relative);
        if ($text === null) {
            return [];
        }
        try {
            $parsed = Yaml::parse($text);
        } catch (\Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /** Staged storage copy first, source tree fallback (same rule as FunnelSetupService::loadPackFile). */
    private function loadPackText(string $relative): ?string
    {
        $path = storage_path('app/feature-packs/'.self::PACK_ID.'/'.$relative);
        if (! is_readable($path)) {
            $path = dirname(base_path()).'/packages/feature-packs/'.self::PACK_ID.'/'.$relative;
        }
        if (! is_readable($path)) {
            return null;
        }
        $text = @file_get_contents($path);

        return $text === false ? null : $text;
    }

    // -------------------------------------------------------------- helpers

    /** @param list<?string> $lines */
    private static function lines(array $lines): ?string
    {
        $kept = [];
        foreach ($lines as $line) {
            if (is_string($line) && trim($line) !== '') {
                $kept[] = trim($line);
            }
        }

        return $kept === [] ? null : implode("\n\n", $kept);
    }

    private static function first(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param list<string> $items */
    private static function bullets(array $items): string
    {
        return implode("\n", array_map(static fn (string $item) => '- '.$item, $items));
    }

    /** @return list<string> */
    private static function splitList(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }
        $parts = preg_split('/\r?\n|;|\s\|\s/', $text) ?: [];

        return array_values(array_filter(array_map(static fn (string $p) => trim($p, " \t-•"), $parts), static fn (string $p) => $p !== ''));
    }

    /** @return list<string> */
    private static function decodeList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : ($value !== '' ? [$value] : []);
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $label = $item['label'] ?? $item['name'] ?? $item['title'] ?? $item['text'] ?? null;
                if (is_string($label) && trim($label) !== '') {
                    $out[] = trim($label);
                }
            } elseif (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }

    /** @param array<int, mixed> $targets each {metric, goal, owner_role} */
    private static function targetsText(array $targets): ?string
    {
        $lines = [];
        foreach ($targets as $target) {
            if (is_string($target) && trim($target) !== '') {
                $lines[] = trim($target);

                continue;
            }
            if (! is_array($target)) {
                continue;
            }
            $metric = trim((string) ($target['metric'] ?? $target['name'] ?? ''));
            $goal = trim((string) ($target['goal'] ?? $target['target'] ?? ''));
            $owner = trim((string) ($target['owner_role'] ?? ''));
            $line = trim($metric.($goal !== '' ? ': '.$goal : ''));
            if ($line === '') {
                continue;
            }
            $lines[] = $line.($owner !== '' ? ' (owner: '.$owner.')' : '');
        }

        return $lines === [] ? null : self::bullets($lines);
    }

    /** @param array<int, mixed> $steps */
    private static function stepsText(array $steps): ?string
    {
        $lines = [];
        $n = 0;
        foreach ($steps as $step) {
            $text = null;
            if (is_string($step)) {
                $text = trim($step);
            } elseif (is_array($step)) {
                $title = trim((string) ($step['title'] ?? $step['name'] ?? $step['step'] ?? $step['text'] ?? ''));
                $detail = trim((string) ($step['description'] ?? $step['detail'] ?? $step['instructions'] ?? ''));
                $text = trim($title.($detail !== '' && $detail !== $title ? ' — '.$detail : ''));
            }
            if ($text === null || $text === '') {
                continue;
            }
            $lines[] = (++$n).'. '.$text;
        }

        return $lines === [] ? null : "Steps:\n".implode("\n", $lines);
    }

    private static function humanizeValue(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    private static function scalarOrJson(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value) && isset($value['start'], $value['end'])) {
            return $value['start'].'–'.$value['end'];
        }

        return (string) json_encode($value);
    }

    private static function toCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
