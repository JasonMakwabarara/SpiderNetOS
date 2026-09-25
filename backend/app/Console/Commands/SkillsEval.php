<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Skills\Eval\EvalRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan skills:eval {slug} [--deterministic] [--live] [--allow-incomplete] [--model=] [--prompt-version=] [--cases=]
 *
 * Replays packages/skills/<slug>/evals/cases.yaml (plan D8 #14). Deterministic
 * (default) checks each case's stored expected_raw through SkillOutputValidator
 * and the expected properties; --live calls the inference plane instead.
 * Prints a table and a coverage line, and writes a JSON report under
 * storage/app/private/skill-evals/<slug>/.
 *
 * Required mode is the default. It succeeds only when at least one case is
 * declared, every case executed, every case gathered its evidence — a schema
 * verdict and every dependency its properties need — and none failed. Until
 * this, the command returned success on `failed === 0`, so a suite of nine
 * cases that all skipped printed `Pass rate: 0%` and exited 0: a check that
 * ran nothing reporting the same status as one that passed everything.
 *
 * --allow-incomplete is the explicit exploratory mode for work in progress. It
 * still fails on a contradicted case, and it prints INCOMPLETE rather than
 * letting an incomplete run read as a result. It is never what CI invokes.
 */
class SkillsEval extends Command
{
    protected $signature = 'skills:eval
        {slug : Skill card slug (packages/skills/<slug>)}
        {--deterministic : Replay stored expected_raw outputs (default)}
        {--live : Call the inference plane for every case}
        {--allow-incomplete : Exploratory mode: succeed on an incomplete run with no failures, marked INCOMPLETE}
        {--model= : Model routing key, recorded in the report (live generation does not yet route by it)}
        {--prompt-version= : Prompt version under test (recorded in the report)}
        {--cases= : Path to an alternative cases.yaml (default packages/skills/<slug>/evals/cases.yaml)}
        {--json : Print the report as JSON}';

    protected $description = 'Run a skill card\'s golden eval cases and report coverage and correctness separately';

    public function handle(EvalRunner $runner): int
    {
        $slug = (string) $this->argument('slug');

        try {
            $report = $runner->run($slug, [
                'deterministic' => ! $this->option('live'),
                'live' => (bool) $this->option('live'),
                'model' => $this->option('model') ?: null,
                'prompt_version' => $this->option('prompt-version') ?: null,
                'cases_file' => $this->option('cases') ?: null,
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $s = $report['summary'];
        $exploratory = (bool) $this->option('allow-incomplete');

        // Correctness always decides; completeness decides too unless the
        // incomplete run was explicitly asked for.
        $succeeded = $s['failed'] === 0 && ($s['complete'] || $exploratory);

        if ($this->option('json')) {
            $this->line((string) json_encode($report + ['mode_policy' => $exploratory ? 'exploratory' : 'required'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $succeeded ? self::SUCCESS : self::FAILURE;
        }

        $this->info("skills:eval {$slug} — {$report['mode']} mode, ".($exploratory ? 'exploratory' : 'required')
            .($report['model'] ? " · requested model {$report['model']}" : '')
            .($report['prompt_version'] ? " · prompt {$report['prompt_version']}" : ''));

        $this->table(
            ['Case', 'Status', 'Validator', 'Properties', 'Detail'],
            array_map(function (array $c): array {
                $props = $c['properties'] ?? [];
                $failedProps = array_values(array_filter($props, fn (array $p): bool => $p['status'] === 'failed'));
                $passedProps = count(array_filter($props, fn (array $p): bool => $p['status'] === 'passed'));
                $detail = $c['note'] ?? implode('; ', array_map(fn (array $p): string => $p['type'].': '.$p['detail'], $failedProps));
                $validator = $c['validator']['ok'] ?? null;
                $judges = count($c['judges'] ?? []);

                return [
                    $c['id'],
                    strtoupper($c['status']),
                    $validator === null ? 'n/a' : ($validator ? 'ok' : 'reject'),
                    ($props === [] ? '-' : $passedProps.'/'.count($props)).($judges > 0 ? " +{$judges} judge (not run)" : ''),
                    Str::limit((string) $detail, 80),
                ];
            }, $report['cases']),
        );

        $this->newLine();
        // Coverage first, then correctness — never a rate over whatever happened to run.
        $this->line(sprintf(
            'Executed %d of %d declared · passed %d · failed %d · skipped %d · unavailable %d',
            $s['executed'], $s['declared'], $s['passed'], $s['failed'], $s['skipped'], $s['unavailable'],
        ));

        if (! $s['complete']) {
            $why = implode('; ', $s['incomplete_reasons']);
            $exploratory
                ? $this->warn("INCOMPLETE — not a pass: {$why}")
                : $this->error("Required suite incomplete: {$why}");
        }
        if ($report['report_path']) {
            $this->line('Report: storage/app/'.$report['report_path']);
        }

        return $succeeded ? self::SUCCESS : self::FAILURE;
    }
}
