<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * One command, one verdict: runs the Python inference doctor (inference/doctor.py) and
 * mirrors its exit code, streaming its PASS/WARN/FAIL lines as they are printed.
 *
 * The module is run as `python -m doctor` from inside the inference directory, which is how
 * the plane is laid out on the production host (/opt/spidernet-inference) and resolves the
 * plane's flat imports. Secret values are never printed by the doctor; this wrapper adds none.
 */
class InferenceDoctor extends Command
{
    protected $signature = 'inference:doctor
        {--url= : Inference service base URL to round-trip (default: services.inference.url)}
        {--json : Print the machine-readable JSON report instead of verdict lines}
        {--env-file= : dotenv file the doctor reads provider keys from (fills names the process env lacks)}';

    protected $description = 'Run the inference plane doctor (python -m doctor) and mirror its verdict';

    public function handle(): int
    {
        $dir = $this->inferenceDirectory();
        if ($dir === null || ! is_file($dir.DIRECTORY_SEPARATOR.'doctor.py')) {
            $this->error('inference/doctor.py not found. Set services.inference.path (INFERENCE_PATH) to the inference directory.');

            return self::FAILURE;
        }

        $command = [$this->python($dir), '-m', 'doctor'];

        $url = trim((string) ($this->option('url') ?: config('services.inference.url', '')));
        if ($url !== '') {
            $command[] = '--inference-url';
            $command[] = $url;
        }
        if ($this->option('json')) {
            $command[] = '--json';
        }
        $envFile = trim((string) $this->option('env-file'));
        if ($envFile !== '') {
            $command[] = '--env-file';
            $command[] = $envFile;
        }

        $result = Process::path($dir)
            ->timeout(600)
            ->env(['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'utf-8'])
            ->run($command, function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

        return $result->exitCode() ?? self::FAILURE;
    }

    private function inferenceDirectory(): ?string
    {
        $configured = trim((string) (config('services.inference.path') ?? ''));
        $candidates = array_values(array_filter([
            $configured !== '' ? $configured : null,
            base_path('../inference'),
            base_path('inference'),
        ]));

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_dir($real)) {
                return $real;
            }
        }

        return null;
    }

    private function python(string $dir): string
    {
        $configured = trim((string) (config('services.inference.python') ?? ''));
        if ($configured !== '') {
            return $configured;
        }
        foreach (['.venv/bin/python', 'venv/bin/python', '.venv/Scripts/python.exe', 'venv/Scripts/python.exe'] as $venv) {
            $candidate = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $venv);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
    }
}
