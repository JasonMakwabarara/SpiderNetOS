<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class SpidernetPackValidate extends Command
{
    protected $signature = 'spidernet:pack-validate 
                            {path? : Relative path to pack.yaml under repository root}
                            {--repo-root= : Override repository root directory}';

    protected $description = 'Validate a Feature Pack pack.yaml structure (delegates to scripts/validate_feature_pack.py).';

    public function handle(): int
    {
        $repoRoot = $this->option('repo-root') ?: dirname(base_path());

        $default = $repoRoot.'/packages/feature-packs/real-estate-crm/pack.yaml';
        $path = $this->argument('path')
            ? $repoRoot.'/'.ltrim((string) $this->argument('path'), '/')
            : $default;

        if (! is_readable($path)) {
            $this->error("Pack manifest not readable: {$path}");

            return self::FAILURE;
        }

        $validatorFs = dirname(base_path()).DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'validate_feature_pack.py';
        if (! is_readable($validatorFs)) {
            $this->error('Validator script missing: '.$validatorFs);

            return self::FAILURE;
        }

        $binary = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';
        $process = new Process(
            [$binary, $validatorFs, $path],
            dirname(dirname($validatorFs)),
            null,
            null,
            120.0
        );
        $process->run();
        echo $process->getOutput();
        fwrite(\STDERR, $process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $this->error('Pack validation failed.');

            return self::FAILURE;
        }

        $this->info('Pack manifest validates.');

        $sigCheck = $this->validateSignatures($path);
        if ($sigCheck !== true) {
            $this->error("Signature validation failed: {$sigCheck}");

            return self::FAILURE;
        }

        $this->info('Signature validation passed.');

        return self::SUCCESS;
    }

    private function validateSignatures(string $manifestPath): true|string
    {
        $yaml = Yaml::parseFile($manifestPath);
        $sigs = $yaml['spec']['signatures'] ?? null;

        if ($sigs === null) {
            return true;
        }

        if (! is_array($sigs)) {
            return 'signatures must be an object';
        }

        if (empty($sigs['publisher'] ?? '') || empty($sigs['signature'] ?? '')) {
            return 'signatures must have publisher and signature fields';
        }

        if (str_starts_with($sigs['signature'], 'placeholder')) {
            return 'signature must not be a placeholder — fail closed';
        }

        if (! preg_match('/^[A-Za-z0-9+\/=_-]+$/', $sigs['signature'])) {
            return 'signature contains invalid characters';
        }

        if (! preg_match('/^[a-z0-9-]+$/', $sigs['publisher'])) {
            return 'publisher must match [a-z0-9-]+';
        }

        return true;
    }
}
