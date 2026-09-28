<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Renders the "Atlas voice options" listening page (plan D7 §7, slice 0) by running the
 * Python CLI inference/voice_previews.py: three fixed lines per persona with whichever TTS
 * keys are present (AZURE_SPEECH_KEY + AZURE_SPEECH_REGION, ELEVENLABS_API_KEY, PIPER_URL),
 * then prints where the page landed. Thin by design; the Python module does the work.
 */
class VoiceRenderPreviews extends Command
{
    protected $signature = 'voice:render-previews
        {--out=storage/app/public/voice-previews : Output directory (relative to the Laravel root unless absolute)}
        {--only= : Restrict to one provider: azure|elevenlabs|piper}';

    protected $description = 'Render the Atlas voice options page (samples + manifest + index.html)';

    public function handle(): int
    {
        $dir = $this->inferenceDirectory();
        if ($dir === null || ! is_file($dir.DIRECTORY_SEPARATOR.'voice_previews.py')) {
            $this->error('inference/voice_previews.py not found. Set services.inference.path (INFERENCE_PATH) to the inference directory.');

            return self::FAILURE;
        }

        $out = trim((string) $this->option('out'));
        if ($out === '') {
            $out = 'storage/app/public/voice-previews';
        }
        if (! preg_match('~^([a-zA-Z]:[\\\\/]|/)~', $out)) {
            $out = base_path($out);
        }

        $command = [$this->python($dir), '-m', 'voice_previews', '--out', $out];
        $only = trim((string) $this->option('only'));
        if ($only !== '') {
            $command[] = '--only';
            $command[] = $only;
        }

        $result = Process::path($dir)
            ->timeout(1800)
            ->env(['PYTHONUNBUFFERED' => '1', 'PYTHONIOENCODING' => 'utf-8'])
            ->run($command, function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

        if (! $result->successful()) {
            return $result->exitCode() ?? self::FAILURE;
        }

        $page = rtrim($out, '\\/').DIRECTORY_SEPARATOR.'index.html';
        $this->newLine();
        $this->info('Page: '.$page);

        $publicRoot = rtrim(storage_path('app'.DIRECTORY_SEPARATOR.'public'), '\\/');
        $normalised = str_replace('\\', '/', rtrim($out, '\\/'));
        $normalisedRoot = str_replace('\\', '/', $publicRoot);
        if (str_starts_with($normalised, $normalisedRoot.'/')) {
            $relative = substr($normalised, strlen($normalisedRoot) + 1);
            $this->line('URL:  '.rtrim((string) config('app.url'), '/').'/storage/'.$relative.'/index.html  (requires php artisan storage:link)');
        }

        return self::SUCCESS;
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
