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
     * @param  array{deterministic?: bool, live?: bool, model?: ?string, prompt_version?: ?string, write_report?: bool, cases_file?: ?string}  $options
     * @return array{slug: string, mode: string, cases_file: string, model: ?string, prompt_version: ?string, ran_at: string, cases: list<array<string, mixed>>, summary: array{total: int, passed: int, failed: int, skipped: int, pass_rate: float}, report_path: ?string}
     */
    public function run(string $slug, array $options = []): array
    {
        $live = (bool) ($options['live'] ?? false);
        $mode = $live ? 'live' : 'deterministic';
        $file = ! empty($options['cases_file']) ? (string) $options['cases_file'] : $this->casesFile($slug);
        $cases = EvalCase::loadAll($file);

        $results = [];
        foreach ($cases as $case) {
            $results[] = $this->runCase($slug, $case, $live, $options);
        }

        $passed = count(array_filter($results, fn (array $r): bool => $r['status'] === 'passed'));
        $failed = count(array_filter($results, fn (array $r): bool => $r['status'] === 'failed'));
        $skipped = count($results) - $passed - $failed;
        $scored = $passed + $failed;

        $report = [
            'slug' => $slug,
            'mode' => $mode,
            'cases_file' => $file,
            'model' => $options['model'] ?? null,
            'prompt_version' => $options['prompt_version'] ?? null,
            'ran_at' => now()->toIso8601String(),
            'cases' => $results,
            'summary' => [
                'total' => count($results),
                'passed' => $passed,
                'failed' => $failed,
                'skipped' => $skipped,
                'pass_rate' => $scored === 0 ? 0.0 : round($passed / $scored, 4),
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
        $base = ['id' => $case->id, 'validator' => null, 'properties' => [], 'judges' => $case->judges, 'note' => null];

        if ($live) {
            try {
                $raw = $this->generate($slug, $case, $options);
            } catch (\Throwable $e) {
                return ['status' => 'failed', 'note' => 'live call failed: '.$e->getMessage()] + $base;
            }
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
        $evaluated = $properties['evaluated'] > 0 || $validatorOk !== null;
        $status = match (true) {
            $properties['failed'] > 0 || $validatorOk === false => 'failed',
            $evaluated => 'passed',
            default => 'skipped',
        };

        return [
            'status' => $status,
            'validator' => $validator,
            'properties' => $properties['results'],
            'properties_passed' => $properties['passed'],
            'output_excerpt' => mb_substr((string) $raw, 0, 400),
            'note' => $evaluated ? null : 'nothing to evaluate',
        ] + $base;
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
     */
    private function generate(string $slug, EvalCase $case, array $options): string
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

        return (string) $result['text'];
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
