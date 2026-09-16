<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Skills\Eval\EvalRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan skills:eval {slug} [--deterministic] [--live] [--model=] [--prompt-version=] [--cases=]
 *
 * Replays packages/skills/<slug>/evals/cases.yaml (plan D8 #14). Deterministic
 * (default) checks each case's stored expected_raw through SkillOutputValidator
 * and the expected properties; --live calls the inference plane instead.
 * Prints a table + pass rate and writes a JSON report under
 * storage/app/private/skill-evals/<slug>/.
 */
class SkillsEval extends Command
{
    protected $signature = 'skills:eval
        {slug : Skill card slug (packages/skills/<slug>)}
        {--deterministic : Replay stored expected_raw outputs (default)}
        {--live : Call the inference plane for every case}
        {--model= : Model routing key to record (and use in live mode)}
        {--prompt-version= : Prompt version under test (recorded in the report)}
        {--cases= : Path to an alternative cases.yaml (default packages/skills/<slug>/evals/cases.yaml)}
        {--json : Print the report as JSON}';

    protected $description = 'Run a skill card\'s golden eval cases (deterministic replay or live) and report the pass rate';

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

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['summary']['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("skills:eval {$slug} — {$report['mode']} mode".($report['model'] ? " · model {$report['model']}" : '').($report['prompt_version'] ? " · prompt {$report['prompt_version']}" : ''));

        $this->table(
            ['Case', 'Status', 'Validator', 'Properties', 'Detail'],
            array_map(function (array $c): array {
                $props = $c['properties'] ?? [];
                $failedProps = array_values(array_filter($props, fn (array $p): bool => $p['status'] === 'failed'));
                $passedProps = count(array_filter($props, fn (array $p): bool => $p['status'] === 'passed'));
                $detail = $c['note'] ?? implode('; ', array_map(fn (array $p): string => $p['type'].': '.$p['detail'], $failedProps));
                $validator = $c['validator']['ok'] ?? null;

                return [
                    $c['id'],
                    strtoupper($c['status']),
                    $validator === null ? 'n/a' : ($validator ? 'ok' : 'reject'),
                    $props === [] ? '-' : $passedProps.'/'.count($props).(count($c['judges'] ?? []) > 0 ? ' +'.count($c['judges']).' judge' : ''),
                    Str::limit((string) $detail, 80),
                ];
            }, $report['cases']),
        );

        $s = $report['summary'];
        $this->newLine();
        $this->line(sprintf('Pass rate: %d%% (%d passed, %d failed, %d skipped of %d)', (int) round($s['pass_rate'] * 100), $s['passed'], $s['failed'], $s['skipped'], $s['total']));
        if ($report['report_path']) {
            $this->line('Report: storage/app/'.$report['report_path']);
        }

        return $s['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
