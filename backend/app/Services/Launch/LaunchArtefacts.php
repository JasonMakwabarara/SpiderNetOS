<?php

declare(strict_types=1);

namespace App\Services\Launch;

use App\Models\BrainFile;
use App\Models\BusinessLaunch;
use App\Services\Brain\BrainStore;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the business-launch pack generates rather than asks for
 * (plan D7 §5): the deterministic finance model, the market research note and
 * the business plan.
 *
 * Numbers and rendering come from the Python service
 * (intelligence/services/business_launch/app.py, `config('launch.service_url')`)
 * over plain Http:: so tests fake it; an LLM never produces a number here.
 *
 * Generated prose is written into the brain with source `agent`
 * (BrainStore::upsertSection / write), which keeps it visibly distinct from
 * the founder's own `human` answers that StageCommitter writes.
 *
 * Binary deliverables (model.xlsx, business-plan.docx/.pdf) are stored on
 * `config('launch.disk')` under launch/<tenant>/<launch>/<file>; an
 * unavailable renderer is recorded as unavailable, never thrown.
 *
 * Every artefact carries "Not legal or financial advice."
 */
class LaunchArtefacts
{
    public const TARGETS = ['research', 'finance', 'plan'];

    public const EVENT_GENERATED = 'launch.artefact.generated';

    public function __construct(
        private readonly BrainStore $brain,
        private readonly EventStore $events,
        private readonly StageCommitter $stages,
        private readonly JurisdictionPack $jurisdictions,
    ) {}

    /**
     * @param  list<string>  $targets  research | finance | plan
     * @return array<string, array<string, mixed>> target => result
     */
    public function generate(BusinessLaunch $launch, array $targets): array
    {
        $out = [];
        foreach ($targets as $target) {
            $out[$target] = match ($target) {
                'research' => $this->research($launch),
                'finance' => $this->financeModel($launch),
                'plan' => $this->plan($launch),
                default => ['ok' => false, 'reason' => 'unknown_target'],
            };
        }

        return $out;
    }

    // ── research ───────────────────────────────────────────────────────

    /**
     * The market note. With `launch.web_research` off (the default) nothing
     * leaves the building: Atlas records the founder's own view and says
     * plainly that it has not been researched yet, rather than inventing
     * comparables.
     *
     * @return array<string, mixed>
     */
    public function research(BusinessLaunch $launch): array
    {
        $answers = $this->stages->runner($launch)->answeredVariables();
        $webResearch = FeatureFlag::on('launch.web_research', $launch->tenant_id);
        $status = $webResearch ? 'researched' : 'founder_guess';

        $body = $webResearch
            ? 'Cited web research is enabled for this workspace; comparables below carry their sources.'
            : 'Not researched yet — this is your own view of the market, written down so the plan is honest about it. Turn on web research (flag `launch.web_research`) and Atlas will look for cited comparables.';

        $lines = [$body, ''];
        if (isset($answers['market.competitors'])) {
            $lines[] = 'Competitors you named: '.$answers['market.competitors'];
            $lines[] = '';
        }
        if (isset($answers['market.market_size_guess'])) {
            $lines[] = 'Your estimate of market size: '.$answers['market.market_size_guess'];
            $lines[] = '';
        }
        $lines[] = '_'.$this->stages->disclaimer().'_';
        $note = implode("\n", $lines);

        $written = [];
        foreach (['market/comparables.md', 'market/tam-sam-som.md'] as $path) {
            $file = $this->brain->upsertSection(
                $launch->tenant_id,
                $path,
                'Research status',
                $note,
                BrainFile::SOURCE_AGENT,
                'launch:'.$launch->id,
            );
            $written[$path] = (int) $file->version;
            $this->recordDeliverable($launch, $path, [
                'kind' => 'brain',
                'format' => 'md',
                'available' => true,
                'version' => (int) $file->version,
                'research_status' => $status,
            ]);
        }

        $this->emit($launch, 'research', ['files' => $written, 'research_status' => $status]);

        return ['ok' => true, 'research_status' => $status, 'files' => $written, 'disclaimer' => $this->stages->disclaimer()];
    }

    // ── finance ────────────────────────────────────────────────────────

    /**
     * POST /finance/model → finance/summary.md (prose) + finance/model.xlsx
     * (live formulas). Every number in the plan comes from this call.
     *
     * @return array<string, mixed>
     */
    public function financeModel(BusinessLaunch $launch): array
    {
        $assumptions = $this->stages->assumptions($launch);
        $missing = $this->stages->missingRequired($launch, $this->stages->stage('finance') ?? []);
        if ($missing !== [] || $assumptions === []) {
            return ['ok' => false, 'reason' => 'missing_answers', 'missing' => $missing];
        }

        $response = $this->call('/finance/model', [
            'assumptions' => $assumptions,
            'include_workbook' => true,
        ]);
        if (! ($response['ok'] ?? false)) {
            return $response;
        }

        $payload = $response['data'];
        $summary = (array) ($payload['summary'] ?? []);
        $currency = (string) ($assumptions['currency'] ?? '');

        $file = $this->brain->upsertSection(
            $launch->tenant_id,
            'finance/summary.md',
            'Summary',
            $this->summaryProse($summary, $currency, (bool) ($payload['formulas_verified'] ?? false)),
            BrainFile::SOURCE_AGENT,
            'launch:'.$launch->id,
        );

        $this->recordDeliverable($launch, 'finance/summary.md', [
            'kind' => 'brain',
            'format' => 'md',
            'available' => true,
            'version' => (int) $file->version,
            'summary' => $summary,
            'formulas_verified' => (bool) ($payload['formulas_verified'] ?? false),
        ]);

        $workbook = (array) ($payload['workbook'] ?? []);
        $stored = null;
        if (! empty($workbook['available']) && is_string($workbook['b64'] ?? null)) {
            $stored = $this->store($launch, 'model.xlsx', base64_decode($workbook['b64'], true) ?: '');
        }
        $this->recordDeliverable($launch, 'finance/model.xlsx', [
            'kind' => 'file',
            'format' => 'xlsx',
            'available' => $stored !== null,
            'reason' => $stored === null ? (string) ($workbook['reason'] ?? 'not generated') : null,
            'stored_path' => $stored,
            'bytes' => $workbook['bytes'] ?? null,
            'sha256' => $workbook['sha256'] ?? null,
        ]);

        $this->emit($launch, 'finance', ['summary' => $summary, 'workbook' => $stored !== null]);

        return [
            'ok' => true,
            'assumptions' => $payload['assumptions'] ?? $assumptions,
            'summary' => $summary,
            'formulas_verified' => (bool) ($payload['formulas_verified'] ?? false),
            'workbook' => ['available' => $stored !== null, 'stored_path' => $stored],
            'disclaimer' => (string) ($payload['disclaimer'] ?? $this->stages->disclaimer()),
        ];
    }

    // ── plan ───────────────────────────────────────────────────────────

    /**
     * POST /docgen/render → plan/business-plan.md in the brain plus the
     * .docx / .pdf deliverables when those renderers are installed.
     *
     * @return array<string, mixed>
     */
    public function plan(BusinessLaunch $launch): array
    {
        if (! FeatureFlag::on('launch.docgen', $launch->tenant_id)) {
            return ['ok' => false, 'reason' => 'docgen_disabled'];
        }

        $missing = $this->stages->missingRequired($launch, $this->stages->stage('plan') ?? []);
        if ($missing !== []) {
            return ['ok' => false, 'reason' => 'missing_answers', 'missing' => $missing];
        }

        $formats = array_values(array_unique(array_map('strval', (array) config('launch.plan_formats', ['md']))));
        $response = $this->call('/docgen/render', [
            'context' => $this->context($launch),
            'formats' => $formats,
        ]);
        if (! ($response['ok'] ?? false)) {
            return $response;
        }

        $payload = $response['data'];
        $markdown = (string) ($payload['markdown'] ?? '');
        if ($markdown !== '' && ! str_contains($markdown, $this->stages->disclaimer())) {
            $markdown .= "\n\n_".$this->stages->disclaimer()."_\n";
        }

        $file = $this->brain->write(
            $launch->tenant_id,
            'plan/business-plan.md',
            $markdown,
            ['generated_on' => now()->toDateString(), 'jurisdiction' => (string) ($launch->jurisdiction ?? '')],
            BrainFile::SOURCE_AGENT,
            'launch:'.$launch->id,
            null,
            null,
            'Business plan drafted from your answers and the finance model',
        );
        $this->recordDeliverable($launch, 'plan/business-plan.md', [
            'kind' => 'brain',
            'format' => 'md',
            'available' => true,
            'version' => (int) $file->version,
        ]);

        foreach (['docx', 'pdf'] as $format) {
            if (! in_array($format, $formats, true)) {
                continue;
            }
            $b64 = $payload[$format.'_b64'] ?? null;
            $stored = is_string($b64) ? $this->store($launch, 'business-plan.'.$format, base64_decode($b64, true) ?: '') : null;
            $this->recordDeliverable($launch, 'plan/business-plan.'.$format, [
                'kind' => 'file',
                'format' => $format,
                'available' => $stored !== null,
                'reason' => $stored === null ? (string) data_get($payload, 'formats.'.$format.'.reason', 'renderer unavailable') : null,
                'stored_path' => $stored,
            ]);
        }

        $this->emit($launch, 'plan', ['version' => (int) $file->version, 'formats' => array_keys((array) ($payload['formats'] ?? []))]);

        return [
            'ok' => true,
            'path' => 'plan/business-plan.md',
            'version' => (int) $file->version,
            'formats' => (array) ($payload['formats'] ?? []),
            'disclaimer' => (string) ($payload['disclaimer'] ?? $this->stages->disclaimer()),
        ];
    }

    /**
     * The docgen template context (templates/business-plan.md.j2): the
     * founder's answers grouped by interview section, the model summary and
     * the jurisdiction checklist. Nothing is invented.
     *
     * @return array<string, mixed>
     */
    public function context(BusinessLaunch $launch): array
    {
        $answers = $this->stages->runner($launch)->answeredVariables();
        $pick = function (string ...$variables) use ($answers): array {
            $out = [];
            foreach ($variables as $variable) {
                $key = substr($variable, strpos($variable, '.') + 1);
                if (isset($answers[$variable])) {
                    $out[$key] = $answers[$variable];
                }
            }

            return $out;
        };

        // Deliverable keys are brain paths, so they contain dots — index them
        // directly rather than through data_get()'s dotted lookup.
        $deliverables = (array) data_get($launch->stage_artifacts ?? [], 'deliverables', []);
        $summary = (array) (($deliverables['finance/summary.md']['summary'] ?? []) ?: []);
        $assumptions = $this->stages->assumptions($launch);
        $code = $launch->jurisdiction ?: StageCommitter::codeFrom($answers['compliance.jurisdiction'] ?? null);
        $checklist = $code !== null ? $this->jurisdictions->checklist($code, $this->complianceProfile($launch)) : null;

        return [
            'generated_on' => now()->toDateString(),
            'disclaimer' => $this->stages->disclaimer(),
            'jurisdiction' => [
                'code' => $code,
                'name' => (string) data_get($checklist, 'jurisdiction.name', $code ?? ''),
            ],
            'business' => $pick('identity.business_name', 'identity.one_liner', 'identity.stage_today')
                + (isset($answers['offer.differentiator']) ? ['differentiator' => $answers['offer.differentiator']] : [])
                + (isset($answers['identity.business_name']) ? ['name' => $answers['identity.business_name']] : []),
            'alignment' => $this->rename($pick('identity.founder_why', 'identity.mission', 'identity.vision', 'identity.ninety_day_target'), ['founder_why' => 'origin']),
            'offer' => $pick('offer.core_offer', 'offer.problem_solved', 'offer.pricing_model', 'offer.proof_points'),
            'customers' => $pick('customers.ideal_customer', 'customers.disqualifiers', 'customers.buying_trigger', 'customers.where_customers_are', 'customers.common_objection'),
            'brand' => $pick('brand.preferred_tone', 'brand.never_say'),
            'market' => $pick('market.competitors', 'market.market_geography', 'market.market_size_guess', 'market.market_trend')
                + ['research_status' => (string) ($deliverables['market/comparables.md']['research_status'] ?? 'founder_guess')],
            'finance' => [
                'currency' => (string) ($assumptions['currency'] ?? ''),
                'assumptions' => $assumptions,
                'summary' => $summary,
                'years' => (array) ($summary['years'] ?? []),
            ],
            'compliance' => $pick('compliance.legal_structure')
                + ['structure' => $answers['compliance.legal_structure'] ?? null, 'items' => array_slice((array) data_get($checklist, 'items', []), 0, 25)],
            'gtm' => $pick('gtm.launch_channel', 'gtm.first_ten_customers', 'gtm.launch_date', 'gtm.marketing_budget', 'gtm.success_signal'),
        ];
    }

    /**
     * ComplianceRadar + jurisdiction-pack facts read off the interview.
     *
     * @return array<string, mixed>
     */
    public function complianceProfile(BusinessLaunch $launch): array
    {
        $answers = $this->stages->runner($launch)->answeredVariables();

        return array_filter([
            'legal_structure' => $answers['compliance.legal_structure'] ?? null,
            'handles_personal_data' => $answers['compliance.handles_personal_data'] ?? null,
            'employees_first_year' => $answers['compliance.employees_first_year'] ?? null,
            'regulated_activity' => $answers['compliance.regulated_activity'] ?? null,
            'already_registered' => $answers['compliance.already_registered'] ?? null,
            // ComplianceRadar's own five-rule heuristic keys.
            'data_handles_pii' => isset($answers['compliance.handles_personal_data']) ?: null,
            'issues_invoices' => isset($answers['offer.pricing_model']) ?: null,
            'hires_contractors' => $answers['compliance.employees_first_year'] ?? null,
        ], fn ($value) => $value !== null && $value !== false);
    }

    // ── deliverables ───────────────────────────────────────────────────

    /**
     * Everything generated so far, newest state first by stages.yaml order.
     *
     * @return list<array<string, mixed>>
     */
    public function deliverables(BusinessLaunch $launch): array
    {
        $recorded = (array) data_get($launch->stage_artifacts ?? [], 'deliverables', []);
        $out = [];

        foreach ($this->stages->stages() as $stage) {
            foreach ((array) ($stage['artefacts'] ?? []) as $artefact) {
                $path = (string) ($artefact['path'] ?? '');
                if ($path === '' || ! isset($recorded[$path])) {
                    continue;
                }
                $out[] = array_merge([
                    'path' => $path,
                    'title' => (string) ($artefact['title'] ?? $path),
                    'stage' => (string) ($stage['id'] ?? ''),
                    'generated_by' => $artefact['generated_by'] ?? null,
                ], (array) $recorded[$path]);
            }
        }

        return $out;
    }

    // ── plumbing ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, data?: array<string, mixed>, reason?: string, status?: int, errors?: mixed}
     */
    private function call(string $path, array $body): array
    {
        try {
            $response = $this->client()->post($path, $body);
        } catch (\Throwable $e) {
            Log::warning('LaunchArtefacts: business-launch service unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            return ['ok' => false, 'reason' => 'service_unavailable'];
        }

        if ($response->failed()) {
            return [
                'ok' => false,
                'reason' => $response->status() === 422 ? 'invalid_assumptions' : 'service_error',
                'status' => $response->status(),
                'errors' => data_get($response->json(), 'detail.errors', data_get($response->json(), 'detail')),
            ];
        }

        return ['ok' => true, 'data' => (array) $response->json()];
    }

    private function client(): PendingRequest
    {
        $key = (string) config('launch.internal_key', '');

        return Http::baseUrl(rtrim((string) config('launch.service_url', 'http://localhost:9010'), '/'))
            ->timeout((int) config('launch.timeout', 60))
            ->acceptJson()
            ->withHeaders($key !== '' ? ['X-Internal-Key' => $key] : []);
    }

    private function store(BusinessLaunch $launch, string $filename, string $bytes): ?string
    {
        if ($bytes === '') {
            return null;
        }
        $path = 'launch/'.$launch->tenant_id.'/'.$launch->id.'/'.$filename;

        try {
            Storage::disk((string) config('launch.disk', 'local'))->put($path, $bytes);
        } catch (\Throwable $e) {
            Log::warning('LaunchArtefacts: could not store deliverable', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return $path;
    }

    /** @param array<string, mixed> $record */
    private function recordDeliverable(BusinessLaunch $launch, string $path, array $record): void
    {
        $artifacts = (array) ($launch->stage_artifacts ?? []);
        $artifacts['deliverables'] = (array) ($artifacts['deliverables'] ?? []);
        $artifacts['deliverables'][$path] = array_merge(
            ['generated_at' => now()->toIso8601String(), 'disclaimer' => $this->stages->disclaimer()],
            array_filter($record, fn ($value) => $value !== null),
        );
        $launch->update(['stage_artifacts' => $artifacts]);
    }

    /** @param array<string, mixed> $payload */
    private function emit(BusinessLaunch $launch, string $target, array $payload): void
    {
        $this->events->append(
            $launch->tenant_id,
            'business_launch',
            $launch->id,
            self::EVENT_GENERATED,
            ['launch_id' => $launch->id, 'target' => $target] + $payload,
        );
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $renames
     * @return array<string, string>
     */
    private function rename(array $values, array $renames): array
    {
        foreach ($renames as $from => $to) {
            if (array_key_exists($from, $values)) {
                $values[$to] = $values[$from];
                unset($values[$from]);
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $summary */
    private function summaryProse(array $summary, string $currency, bool $verified): string
    {
        $money = function (mixed $value) use ($currency): string {
            if (! is_numeric($value)) {
                return '—';
            }

            return trim($currency.' '.number_format((float) $value, 0));
        };

        $lines = [
            'Straight from the model — not a guess, and not advice.',
            '',
            '- Year one revenue: '.$money($summary['year1_revenue'] ?? null),
            '- Year one EBITDA: '.$money($summary['year1_ebitda'] ?? null),
            '- Lowest your cash gets: '.$money($summary['cash_low_point'] ?? null).' (month '.((string) ($summary['cash_low_month'] ?? '—')).')',
            '- Runway: '.((string) ($summary['runway_months'] ?? '—')).' months',
            '- Break-even: '.($summary['breakeven_month'] ? 'month '.$summary['breakeven_month'] : 'not within the modelled horizon'),
            '',
            $verified
                ? 'The spreadsheet formulas were recalculated and matched these figures.'
                : 'Formula verification did not run — treat the spreadsheet as the source of truth.',
            '',
            '_'.$this->stages->disclaimer().'_',
        ];

        return implode("\n", $lines);
    }
}
