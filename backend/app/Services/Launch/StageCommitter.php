<?php

declare(strict_types=1);

namespace App\Services\Launch;

use App\Models\BrainFile;
use App\Models\BusinessLaunch;
use App\Services\Brain\BrainStore;
use App\Services\EventStore;
use App\Services\Interviews\ArrayAnswerStore;
use App\Services\Interviews\InterviewRunner;
use App\Services\Interviews\ModelAnswerStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns a finished interview stage into brain files (plan D7 §5,
 * packages/feature-packs/business-launch/stages.yaml).
 *
 * A stage owns a set of artefacts; each artefact declares the interview
 * variables it `requires` (cannot be skipped) and the ones it `uses`. When
 * nothing required is missing, commit() composes every file the stage owns
 * from ALL answers targeting that path — one BrainStore::write per file, so a
 * stage is exactly one version bump per file — and emits
 * `launch.stage.committed`.
 *
 * Answers are the founder's own words, so they are written with source
 * `human`; generated prose (market narrative, finance summary, the plan) is
 * written by LaunchArtefacts with source `agent`.
 *
 * Artefacts marked `generated_by` are NOT written here — numbers and drafts
 * come from the deterministic Python service, never from a stage commit.
 */
class StageCommitter
{
    public const DISCLAIMER = 'Not legal or financial advice.';

    public const EVENT_STAGE_COMMITTED = 'launch.stage.committed';

    private ?InterviewRunner $reader = null;

    /** @var array<int, InterviewRunner> spl_object_id(launch) => runner */
    private array $runners = [];

    public function __construct(
        private readonly BrainStore $brain,
        private readonly EventStore $events,
        private readonly JurisdictionPack $jurisdictions,
    ) {}

    // ── stages.yaml ────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function stages(): array
    {
        $parsed = $this->reader()->loadPackFile('stages.yaml');

        return array_values(array_filter((array) ($parsed['stages'] ?? []), 'is_array'));
    }

    /** @return array<string, mixed>|null */
    public function stage(string $stageId): ?array
    {
        foreach ($this->stages() as $stage) {
            if ((string) ($stage['id'] ?? '') === $stageId) {
                return $stage;
            }
        }

        return null;
    }

    /** @return list<string> stage ids in order */
    public function stageIds(): array
    {
        return array_map(fn (array $s) => (string) $s['id'], $this->stages());
    }

    public function disclaimer(): string
    {
        $parsed = $this->reader()->loadPackFile('stages.yaml');

        return (string) ($parsed['disclaimer'] ?? self::DISCLAIMER);
    }

    /**
     * Variables a stage cannot be committed without.
     *
     * @param  array<string, mixed>  $stage
     * @return list<string>
     */
    public function requiredVariables(array $stage): array
    {
        $out = [];
        foreach ((array) ($stage['artefacts'] ?? []) as $artefact) {
            foreach ((array) ($artefact['requires'] ?? []) as $variable) {
                $out[] = (string) $variable;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Optional variables a stage would use if the founder answers them.
     *
     * @param  array<string, mixed>  $stage
     * @return list<string>
     */
    public function optionalVariables(array $stage): array
    {
        $out = [];
        foreach ((array) ($stage['artefacts'] ?? []) as $artefact) {
            foreach ((array) ($artefact['uses'] ?? []) as $variable) {
                $out[] = (string) $variable;
            }
        }

        return array_values(array_diff(array_unique($out), $this->requiredVariables($stage)));
    }

    /**
     * @param  array<string, mixed>  $stage
     * @return list<string> required variables still unanswered
     */
    public function missingRequired(BusinessLaunch $launch, array $stage): array
    {
        $answered = $this->runner($launch)->answeredVariables();

        return array_values(array_filter(
            $this->requiredVariables($stage),
            fn (string $variable) => ! array_key_exists($variable, $answered),
        ));
    }

    /**
     * A fingerprint of the answers one stage draws on — the change detector
     * that keeps commit() from rewriting every file after every answer.
     *
     * @param  array<string, mixed>  $stage
     */
    public function stageAnswersHash(BusinessLaunch $launch, array $stage): string
    {
        $answered = $this->runner($launch)->answeredVariables();
        $subset = [];
        foreach (array_merge($this->requiredVariables($stage), $this->optionalVariables($stage)) as $variable) {
            if (array_key_exists($variable, $answered)) {
                $subset[$variable] = $answered[$variable];
            }
        }
        ksort($subset);

        return hash('sha256', json_encode($subset, JSON_THROW_ON_ERROR));
    }

    /** True when every artefact this stage generates has already been produced. */
    public function generatedArtefactsPresent(BusinessLaunch $launch, array $stage): bool
    {
        $deliverables = (array) data_get($launch->stage_artifacts ?? [], 'deliverables', []);
        foreach ((array) ($stage['artefacts'] ?? []) as $artefact) {
            if (empty($artefact['generated_by'])) {
                continue;
            }
            if (empty($deliverables[(string) ($artefact['path'] ?? '')])) {
                return false;
            }
        }

        return true;
    }

    // ── commit ─────────────────────────────────────────────────────────

    /**
     * Write every non-generated brain artefact the stage owns.
     *
     * @return array{stage: string, committed: bool, files: array<string, int>, missing: list<string>, disclaimer: string}
     */
    public function commit(BusinessLaunch $launch, string $stageId): array
    {
        $stage = $this->stage($stageId);
        if ($stage === null) {
            return ['stage' => $stageId, 'committed' => false, 'files' => [], 'missing' => [], 'disclaimer' => $this->disclaimer(), 'error' => 'unknown_stage'];
        }

        $missing = $this->missingRequired($launch, $stage);
        if ($missing !== []) {
            return ['stage' => $stageId, 'committed' => false, 'files' => [], 'missing' => $missing, 'disclaimer' => $this->disclaimer()];
        }

        // Committing is idempotent and the state machine re-checks every
        // stage after every answer, so a stage whose OWN answers have not
        // moved since its last commit short-circuits instead of re-writing.
        $hash = $this->stageAnswersHash($launch, $stage);
        $previous = (array) data_get($launch->stage_artifacts ?? [], 'stages.'.$stageId, []);
        if (($previous['answers_hash'] ?? null) === $hash) {
            return ['stage' => $stageId, 'committed' => true, 'files' => (array) ($previous['files'] ?? []), 'missing' => [], 'disclaimer' => $this->disclaimer(), 'unchanged' => true];
        }

        $files = [];
        foreach ((array) ($stage['artefacts'] ?? []) as $artefact) {
            if (! is_array($artefact) || ($artefact['kind'] ?? 'brain') !== 'brain' || ! empty($artefact['generated_by'])) {
                continue;   // files and generated prose belong to LaunchArtefacts
            }
            $path = (string) ($artefact['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $composed = $this->compose($launch, $path, $artefact);
            if ($composed === null) {
                continue;   // nothing answered for this file yet
            }

            $file = $this->brain->write(
                $launch->tenant_id,
                $path,
                $composed['content'],
                $composed['frontmatter'],
                BrainFile::SOURCE_HUMAN,
                'launch:'.$launch->id,
                null,
                null,
                'Business launch — '.(string) ($stage['title'] ?? $stageId),
            );
            $files[$path] = (int) $file->version;
        }

        if ($files === []) {
            // Nothing this stage owns has an answer yet (a stage whose only
            // artefacts are generated, or whose optional questions were all
            // skipped): the gate passed, but there is no version to record.
            return ['stage' => $stageId, 'committed' => true, 'files' => [], 'missing' => [], 'disclaimer' => $this->disclaimer(), 'unchanged' => true];
        }

        $artifacts = (array) ($launch->stage_artifacts ?? []);
        $artifacts['stages'] = (array) ($artifacts['stages'] ?? []);
        $artifacts['stages'][$stageId] = [
            'committed_at' => now()->toIso8601String(),
            'files' => $files,
            'answers_hash' => $hash,
        ];
        $launch->update(['stage_artifacts' => $artifacts]);

        $this->events->append(
            $launch->tenant_id,
            'business_launch',
            $launch->id,
            self::EVENT_STAGE_COMMITTED,
            [
                'launch_id' => $launch->id,
                'pack_id' => $launch->pack_id,
                'stage' => $stageId,
                'status' => $launch->status,
                'files' => $files,
                'disclaimer' => $this->disclaimer(),
            ],
        );

        return ['stage' => $stageId, 'committed' => true, 'files' => $files, 'missing' => [], 'disclaimer' => $this->disclaimer()];
    }

    // ── content ────────────────────────────────────────────────────────

    /**
     * Compose one brain file from every answer whose `brain_target.path` is
     * this file — which is why re-committing a later stage keeps the earlier
     * sections instead of blanking them.
     *
     * @param  array<string, mixed>  $artefact
     * @return array{content: string, frontmatter: array<string, mixed>}|null
     */
    public function compose(BusinessLaunch $launch, string $path, array $artefact = []): ?array
    {
        if (str_ends_with($path, '.yaml') || ($artefact['format'] ?? null) === 'yaml') {
            $yaml = $this->composeYaml($launch, $path);

            return $yaml === null ? null : ['content' => $yaml, 'frontmatter' => []];
        }

        $frontmatter = [];
        $sections = $this->withAnswers($launch, $path, $frontmatter);
        if ($sections === []) {
            return null;    // nothing answered for this file yet
        }

        $lines = ['# '.(string) ($artefact['title'] ?? $this->titleFor($path)), ''];
        foreach ($sections as $heading => $paragraphs) {
            $lines[] = '## '.$heading;
            $lines[] = '';
            foreach ($paragraphs as $paragraph) {
                $lines[] = $paragraph;
                $lines[] = '';
            }
        }

        // Sections the interview does not own — the agent's research note, a
        // section the founder wrote by hand in the brain — survive a
        // re-commit instead of being overwritten by it.
        foreach ($this->preserved($launch, $path, $sections) as $heading => $body) {
            $lines[] = '## '.$heading;
            $lines[] = '';
            $lines[] = $body;
            $lines[] = '';
        }

        $lines[] = '---';
        $lines[] = '';
        $lines[] = '_'.$this->disclaimer().'_';

        return ['content' => implode("\n", $lines)."\n", 'frontmatter' => $frontmatter];
    }

    /**
     * finance/assumptions.yaml — the only yaml artefact the interview fills.
     * Numbers are parsed out of the founder's own words ("about £5,000" →
     * 5000) and the raw answers are kept beside them so the model's inputs
     * are always traceable to what was said.
     */
    private function composeYaml(BusinessLaunch $launch, string $path): ?string
    {
        $assumptions = $this->assumptions($launch);
        if ($assumptions === []) {
            return null;
        }

        $answers = $launch->answerValues();
        $captured = [];
        foreach ($this->targets($path) as $target) {
            $answer = trim((string) ($answers[$target['question_id']] ?? ''));
            if ($answer !== '') {
                $captured[$target['question_id']] = $answer;
            }
        }

        $body = Yaml::dump($assumptions + ($captured === [] ? [] : ['captured' => $captured]), 4, 2);

        return "# Financial assumptions — captured from your own answers.\n"
            .'# '.$this->disclaimer()."\n"
            .$body;
    }

    /**
     * The deterministic finance model's inputs (intelligence/services/
     * business_launch/finance_model.py Assumptions). Currency comes from the
     * chosen jurisdiction, never from a guess.
     *
     * @return array<string, mixed>
     */
    public function assumptions(BusinessLaunch $launch): array
    {
        $answers = $this->runner($launch)->answeredVariables();

        $map = [
            'starting_cash' => 'finance.starting_cash',
            'setup_costs' => 'finance.setup_costs',
            'price_per_unit' => 'finance.price_per_unit',
            'units_month_one' => 'finance.units_month_one',
            'monthly_growth_pct' => 'finance.monthly_growth',
            'cost_of_sale_pct' => 'finance.cost_of_sale_pct',
            'fixed_monthly_costs' => 'finance.fixed_monthly_costs',
            'marketing_monthly' => 'gtm.marketing_budget',
        ];

        $out = [];
        foreach ($map as $key => $variable) {
            $number = self::numberFrom($answers[$variable] ?? null);
            if ($number !== null) {
                $out[$key] = $number;
            }
        }
        if ($out === []) {
            return [];
        }

        $code = $launch->jurisdiction ?: self::codeFrom($answers['compliance.jurisdiction'] ?? null);
        $currency = $this->jurisdictions->currency($code);

        return [
            'currency' => $currency ?? 'GBP',
            'months' => (int) config('launch.model_months', 36),
        ] + $out + ['marketing_monthly' => 0.0];
    }

    /**
     * Every interview question that targets one brain path, in file order.
     *
     * @return list<array{question_id: string, section: string, frontmatter: ?string, answer: ?string}>
     */
    private function targets(string $path): array
    {
        $out = [];
        foreach ($this->reader()->loadInterviewQuestions()['sections'] ?? [] as $section) {
            foreach ((array) ($section['questions'] ?? []) as $question) {
                foreach ($this->brainTargets($question) as $target) {
                    if ((string) ($target['path'] ?? '') !== $path) {
                        continue;
                    }
                    $out[] = [
                        'question_id' => (string) ($question['id'] ?? ''),
                        'section' => (string) ($target['section'] ?? ''),
                        'frontmatter' => isset($target['frontmatter']) ? (string) $target['frontmatter'] : null,
                        'answer' => null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * `brain_target` plus any `mirror_to` entries.
     *
     * @param  array<string, mixed>  $question
     * @return list<array<string, mixed>>
     */
    private function brainTargets(array $question): array
    {
        $targets = [];
        if (is_array($question['brain_target'] ?? null)) {
            $targets[] = $question['brain_target'];
        }
        foreach ((array) ($question['mirror_to'] ?? []) as $mirror) {
            if (is_array($mirror)) {
                $targets[] = $mirror;
            }
        }

        return $targets;
    }

    /**
     * Fill the section map with this launch's answers (targets() is pure
     * structure so it can be reasoned about without a row).
     *
     * @param  array<string, mixed>  $frontmatter
     * @return array<string, list<string>>
     */
    private function withAnswers(BusinessLaunch $launch, string $path, array &$frontmatter): array
    {
        $answers = $launch->answerValues();
        $out = [];

        foreach ($this->targets($path) as $target) {
            $answer = trim((string) ($answers[$target['question_id']] ?? ''));
            if ($answer === '') {
                continue;
            }
            $heading = $target['section'] !== '' ? $target['section'] : 'Notes';
            $out[$heading] = array_merge($out[$heading] ?? [], [$answer]);
            if ($target['frontmatter'] !== null) {
                $frontmatter[$target['frontmatter']] = $answer;
            }
        }

        return $out;
    }

    /**
     * Existing `## sections` of a brain file that this interview does not
     * write, with the trailing disclaimer stripped so it is not duplicated.
     *
     * @param  array<string, list<string>>  $owned
     * @return array<string, string> heading => body
     */
    private function preserved(BusinessLaunch $launch, string $path, array $owned): array
    {
        $existing = $this->brain->read($launch->tenant_id, $path);
        if ($existing === null) {
            return [];
        }

        $out = [];
        foreach ($this->brain->sections((string) $existing->content) as $heading => $body) {
            if (array_key_exists($heading, $owned)) {
                continue;
            }
            $body = trim(preg_replace('/\n*-{3,}\n*_?'.preg_quote($this->disclaimer(), '/').'_?\s*$/u', '', $body) ?? $body);
            $body = trim(preg_replace('/\n*_?'.preg_quote($this->disclaimer(), '/').'_?\s*$/u', '', $body) ?? $body);
            if ($body !== '') {
                $out[$heading] = $body;
            }
        }

        return $out;
    }

    private function titleFor(string $path): string
    {
        $base = str_replace(['-', '_'], ' ', pathinfo($path, PATHINFO_FILENAME));

        return ucfirst($base);
    }

    /** "about £5,000 to start" → 5000.0; "around 5%" → 5.0; "12k" → 12000.0. */
    public static function numberFrom(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }
        $text = str_replace([',', ' '], ['', ''], $text);

        if (! preg_match('/-?\d+(?:\.\d+)?/', $text, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $number = (float) $match[0][0];
        $suffix = substr($text, $match[0][1] + strlen($match[0][0]), 1);
        if ($suffix === 'k') {
            $number *= 1000;
        } elseif ($suffix === 'm') {
            $number *= 1000000;
        }

        return $number;
    }

    /** "South Africa" / "za" → za. */
    public static function codeFrom(mixed $value): ?string
    {
        $text = strtolower(trim((string) $value));
        if ($text === '') {
            return null;
        }
        foreach ([
            'uk' => ['uk', 'united kingdom', 'britain', 'england', 'scotland', 'wales', 'gb'],
            'za' => ['za', 'south africa', 'rsa'],
            'zw' => ['zw', 'zimbabwe', 'zim'],
        ] as $code => $needles) {
            foreach ($needles as $needle) {
                if ($text === $needle || str_contains($text, $needle)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * The interview runner bound to this launch's answers. Memoised per model
     * instance: the state machine re-reads answers many times per request and
     * every fresh runner would re-parse the pack's yaml.
     */
    public function runner(BusinessLaunch $launch): InterviewRunner
    {
        return $this->runners[spl_object_id($launch)] ??= new InterviewRunner(
            (string) config('launch.pack_id', 'business-launch'),
            new ModelAnswerStore($launch, 'interview_answers', null),
        );
    }

    private function reader(): InterviewRunner
    {
        return $this->reader ??= new InterviewRunner(
            (string) config('launch.pack_id', 'business-launch'),
            new ArrayAnswerStore,
        );
    }
}
