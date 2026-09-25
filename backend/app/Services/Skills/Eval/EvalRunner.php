<?php

declare(strict_types=1);

namespace App\Services\Skills\Eval;

use App\Services\Inference\InferencePlaneClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Skill eval harness (plan D8 #14). Deterministic mode replays every case's
 * stored expected_raw through SkillOutputValidator (Stream B1's card
 * validator, when present) and the property checks; live mode calls the
 * inference plane with the card's task prompt + fixture brain + inputs and
 * scores the same way. Writes a JSON report to
 * storage/app/private/skill-evals/<slug>/<ts>.json.
 */
class EvalRunner
{
    public const VALIDATOR = 'App\\Services\\Skills\\SkillOutputValidator';

    public const REGISTRY = 'App\\Services\\Skills\\SkillRegistry';

    public const REPORT_DIR = 'private/skill-evals';

    public function __construct(private readonly PropertyChecker $checker) {}

    public function skillsRoot(): string
    {
        return rtrim((string) config('agents.skills_root'), '/\\');
    }

    public function casesFile(string $slug): string
    {
        return $this->skillsRoot()."/{$slug}/evals/cases.yaml";
    }

    /**
     * Reports what happened and leaves the verdict to the caller: the command
     * decides the exit status, which keeps the gate testable without shelling
     * out.
     *
     * Coverage and correctness are reported apart. The old summary carried a
     * single `pass_rate` over whichever cases happened to execute, which is how
     * `0 passed, 0 failed, 9 skipped` printed as a rate and exited 0 — a rate
     * over an empty executed set says nothing, and it read as a result.
     *
     * @param  array{deterministic?: bool, live?: bool, model?: ?string, prompt_version?: ?string, write_report?: bool, cases_file?: ?string}  $options
     * @return array{slug: string, mode: string, cases_file: string, prompt_version: ?string, ran_at: string, cases: list<array<string, mixed>>, summary: array{declared: int, executed: int, passed: int, failed: int, skipped: int, unavailable: int, complete: bool, incomplete_reasons: list<string>}, report_path: ?string}
     */
    public function run(string $slug, array $options = []): array
    {
        // A requested model is refused rather than recorded. Generation does
        // not route by it (step 6 of the delivery order), so a report carrying
        // it would name a model that did not run. What did run is recorded per
        // case, from the plane's own answer.
        if (! empty($options['model'])) {
            throw new \RuntimeException('A model cannot be selected yet: live generation does not route by it, so the report would name a model that did not run. Each live case records the model the plane actually used.');
        }

        $live = (bool) ($options['live'] ?? false);
        $mode = $live ? 'live' : 'deterministic';
        $file = ! empty($options['cases_file']) ? (string) $options['cases_file'] : $this->casesFile($slug);
        $cases = EvalCase::loadAll($file);

        $results = [];
        foreach ($cases as $case) {
            $results[] = $this->runCase($slug, $case, $live, $options);
        }

        $count = static fn (string $status): int => count(array_filter($results, fn (array $r): bool => $r['status'] === $status));
        $declared = count($results);
        $skipped = $count('skipped');
        $unavailable = $count('unavailable');

        // Every way the evidence falls short, named rather than folded into a rate.
        $incomplete = [];
        if ($declared === 0) {
            $incomplete[] = 'no cases declared';
        }
        if ($skipped > 0) {
            $incomplete[] = "{$skipped} of {$declared} case(s) did not execute";
        }
        if ($unavailable > 0) {
            $incomplete[] = "{$unavailable} case(s) could not gather required evidence — a schema verdict, a dependency, a judge or the generation itself";
        }

        $report = [
            'slug' => $slug,
            'mode' => $mode,
            'cases_file' => $file,
            'prompt_version' => $options['prompt_version'] ?? null,
            'ran_at' => now()->toIso8601String(),
            'cases' => $results,
            'summary' => [
                'declared' => $declared,
                'executed' => $declared - $skipped,
                'passed' => $count('passed'),
                'failed' => $count('failed'),
                'skipped' => $skipped,
                'unavailable' => $unavailable,
                'complete' => $incomplete === [],
                'incomplete_reasons' => $incomplete,
            ],
            'report_path' => null,
        ];

        if ($options['write_report'] ?? true) {
            $report['report_path'] = $this->writeReport($slug, $report);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function runCase(string $slug, EvalCase $case, bool $live, array $options = []): array
    {
        // Judges are declared live-only (see EvalCase): a replayed golden
        // output tests the contract, and a judge scores the quality of a
        // generation. So in deterministic mode they are excluded by the mode's
        // declared contract, and reported as such rather than as evaluated. In
        // live mode they are required — and nothing executes them yet, so a
        // case that declares one cannot pass there (see below).
        $base = [
            'id' => $case->id, 'validator' => null, 'properties' => [], 'judges' => $case->judges,
            'judges_status' => match (true) {
                $case->judges === [] => 'none',
                $live => 'not_executed',
                default => 'not_applicable_in_mode',
            },
            'missing_dependencies' => [], 'generated_by' => null, 'note' => null,
        ];

        if ($live) {
            try {
                $generation = $this->generate($slug, $case, $options);
            } catch (\Throwable $e) {
                // The evaluator could not obtain an output — an absence of
                // evidence, not a contradiction, and a different fix. It still
                // blocks: unavailable is never a pass.
                return ['status' => 'unavailable', 'note' => 'live call failed: '.$e->getMessage()] + $base;
            }
            $raw = $generation['text'];
            // What actually ran, from the plane's own answer — never the model
            // the caller asked for.
            $base['generated_by'] = ['model' => $generation['model'], 'provider' => $generation['provider']];
        } else {
            $raw = $case->expectedRaw;
            if ($raw === null) {
                return ['status' => 'skipped', 'note' => 'no expected_raw — needs --live'] + $base;
            }
        }

        $decoded = json_decode((string) $raw, true);
        $output = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $raw;

        $validator = $this->validate($slug, (string) $raw, $case);
        $properties = $this->checker->check($case->properties, $output, $case, $validator);

        $validatorOk = $validator['ok'] ?? null;
        $missing = self::missingDependencies($properties['results']);

        $judgesPending = $base['judges_status'] === 'not_executed';

        // A contradiction fails the case whatever else is true — including a
        // judge that never ran beside it. Short of one, a case is only as good
        // as the evidence it could gather: no schema verdict, a property that
        // could not run for want of its input, or a required judge that did
        // not execute leaves it unavailable — never passed. Before this, a
        // case with no verdict still read `passed` whenever its properties
        // did, because a null validator was one of two ways to count as
        // evaluated.
        $status = match (true) {
            $properties['failed'] > 0 || $validatorOk === false => 'failed',
            $validatorOk === null, $missing !== [], $judgesPending => 'unavailable',
            default => 'passed',
        };

        return [
            'status' => $status,
            'validator' => $validator,
            'properties' => $properties['results'],
            'properties_passed' => $properties['passed'],
            // Kept on every case, failed ones included: a contradiction and a
            // missing input need different fixes, and the first must not hide
            // the second.
            'missing_dependencies' => $missing,
            'output_excerpt' => mb_substr((string) $raw, 0, 400),
            'note' => match (true) {
                $status !== 'unavailable' => null,
                $validatorOk === null => 'no schema verdict: '.($validator['note'] ?? 'validator unavailable'),
                $missing !== [] => 'missing dependency: '.implode(', ', $missing),
                default => 'judge not executed: '.count($case->judges).' required judge(s) declared, and no judge executor exists yet',
            },
        ] + $base;
    }

    /**
     * Properties that could not run because an input they need was absent,
     * named with the reason. Status strings are left as they are — a
     * dependency-missing property still reports `skipped` — so the case-level
     * outcome is where the absence becomes blocking.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<string>
     */
    private static function missingDependencies(array $results): array
    {
        $missing = [];
        foreach ($results as $row) {
            $reason = Reason::tryFrom((string) ($row['reason'] ?? ''));
            if ($reason !== null && $reason->isDependencyMissing() && $row['status'] !== 'passed') {
                $missing[] = $row['type'].' ('.$reason->value.')';
            }
        }

        return $missing;
    }

    /**
     * SkillOutputValidator::validate(SkillCard, raw, facts) when Stream B1's
     * classes are installed and the card exists under skills_root; facts are
     * derived from the fixture brain the way SkillPromptBuilder::facts() does
     * (offer frontmatter pricing / proof_points / links / products, affiliate
     * urls). Absent or incompatible → {ok: null}.
     *
     * @return array{ok: ?bool, errors: list<string>, note: ?string}
     */
    public function validate(string $slug, string $raw, ?EvalCase $case = null): array
    {
        if (! class_exists(self::VALIDATOR) || ! class_exists(self::REGISTRY)) {
            return ['ok' => null, 'errors' => [], 'note' => 'validator not installed'];
        }

        try {
            $registryClass = self::REGISTRY;
            $registry = new $registryClass($this->skillsRoot());
            $card = $registry->get($slug);
            if ($card === null) {
                return ['ok' => null, 'errors' => [], 'note' => "no card for {$slug} under ".$this->skillsRoot()];
            }

            $validatorClass = self::VALIDATOR;
            $validator = new $validatorClass($registry);
            $result = $validator->validate($card, $raw, $case !== null ? $this->factsFor($case) : []);

            return $this->normaliseValidatorResult($result);
        } catch (\Throwable $e) {
            Log::info('skills.eval.validator_unavailable', ['slug' => $slug, 'error' => $e->getMessage()]);

            return ['ok' => null, 'errors' => [], 'note' => 'validator error: '.$e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function factsFor(EvalCase $case): array
    {
        $facts = ['pricing' => null, 'proof_points' => [], 'links' => [], 'products' => [], 'affiliate' => []];

        $offer = $case->fixtureFrontmatter('offer/offer.md');
        $facts['pricing'] = $offer['pricing'] ?? null;
        $facts['proof_points'] = array_values(array_map('strval', (array) ($offer['proof_points'] ?? [])));
        $facts['links'] = array_values(array_map('strval', (array) ($offer['links'] ?? [])));
        $facts['products'] = array_values(array_map(fn ($p) => is_array($p) ? (string) json_encode($p) : (string) $p, (array) ($offer['products'] ?? [])));

        $affiliate = $case->fixtureFrontmatter('programs/affiliate.md');
        foreach (['join_url', 'commission', 'cookie_days', 'payout', 'terms_url', 'portal_subdomain', 'postal_address'] as $key) {
            // `isset()` already excludes null, so a `!== null` clause here would
            // assert nothing — the same shape as the dead conditions Larastan found
            // in PropertyArg.
            if (isset($affiliate[$key]) && $affiliate[$key] !== '') {
                $facts['affiliate'][$key] = is_scalar($affiliate[$key]) ? $affiliate[$key] : json_encode($affiliate[$key]);
            }
        }
        foreach (['join_url', 'terms_url'] as $key) {
            if (! empty($affiliate[$key]) && is_string($affiliate[$key])) {
                $facts['links'][] = $affiliate[$key];
            }
        }
        $facts['links'] = array_values(array_unique($facts['links']));

        return $facts;
    }

    /** @return array{ok: ?bool, errors: list<string>, note: ?string} */
    private function normaliseValidatorResult(mixed $result): array
    {
        $errorsOf = function (mixed $errors): array {
            $out = [];
            foreach ((array) $errors as $e) {
                $out[] = is_array($e) ? trim(((string) ($e['code'] ?? '')).' '.((string) ($e['path'] ?? '')).': '.((string) ($e['message'] ?? ''))) : (string) $e;
            }

            return $out;
        };

        if (is_bool($result)) {
            return ['ok' => $result, 'errors' => [], 'note' => null];
        }
        if (is_array($result)) {
            $ok = $result['ok'] ?? $result['valid'] ?? $result['passed'] ?? null;
            $errors = $errorsOf($result['errors'] ?? $result['reasons'] ?? $result['violations'] ?? []);

            return ['ok' => is_bool($ok) ? $ok : ($errors !== [] ? false : null), 'errors' => $errors, 'note' => null];
        }
        if (is_object($result)) {
            foreach (['ok', 'valid', 'passed'] as $prop) {
                if (isset($result->{$prop}) && is_bool($result->{$prop})) {
                    return ['ok' => $result->{$prop}, 'errors' => $errorsOf($result->errors ?? []), 'note' => null];
                }
                if (method_exists($result, $prop)) {
                    $v = $result->{$prop}();
                    if (is_bool($v)) {
                        return ['ok' => $v, 'errors' => $errorsOf(method_exists($result, 'errors') ? $result->errors() : []), 'note' => null];
                    }
                }
            }
        }

        return ['ok' => null, 'errors' => [], 'note' => 'unrecognised validator result'];
    }

    /**
     * Live mode: task prompt + fixture brain + inputs → inference plane.
     *
     * @param  array<string, mixed>  $options
     * @return array{text: string, model: string, provider: string}
     */
    private function generate(string $slug, EvalCase $case, array $options): array
    {
        $root = $this->skillsRoot()."/{$slug}";
        $task = is_file("{$root}/prompts/task.md") ? (string) file_get_contents("{$root}/prompts/task.md") : "Run the {$slug} skill.";

        $brain = '';
        foreach ($case->fixtureBrain as $path => $content) {
            $brain .= "<FILE path=\"{$path}\">\n{$content}\n</FILE>\n";
        }

        $prompt = $task."\n\n<BRAIN>\n".$brain."</BRAIN>\n\n<INPUTS>\n"
            .json_encode($case->inputs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n</INPUTS>\n\nReturn only the JSON output the skill contract specifies.";

        $client = app(InferencePlaneClient::class);
        $result = $client->generate($prompt, null, 'growth', 0.25, 2048, false, 0.0);

        return ['text' => (string) $result['text'], 'model' => (string) $result['model'], 'provider' => (string) $result['provider']];
    }

    /** @param array<string, mixed> $report */
    private function writeReport(string $slug, array $report): ?string
    {
        $path = self::REPORT_DIR.'/'.$slug.'/'.now()->format('Ymd-His').'.json';
        try {
            Storage::disk('local')->put($path, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $path;
        } catch (\Throwable $e) {
            Log::warning('skills.eval.report_failed', ['slug' => $slug, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
