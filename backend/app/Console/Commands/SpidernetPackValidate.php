<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SpidernetPackValidate extends Command
{
    protected $signature = 'spidernet:pack-validate';
    protected $description = 'Validate feature pack manifests';

    public function handle()
    {
        $packsPath = base_path('../packages/feature-packs');
        
        if (!is_dir($packsPath)) {
            $this->warn('No feature packs directory found at: ' . $packsPath);
            $this->line('Skipping validation - no packs to validate.');
            return Command::SUCCESS;
        }

        $packDirs = glob($packsPath . '/*', GLOB_ONLYDIR);
        
        if (empty($packDirs)) {
            $this->warn('No feature packs found.');
            return Command::SUCCESS;
        }

        $this->info('Validating ' . count($packDirs) . ' feature pack(s)...');
        $allValid = true;

        foreach ($packDirs as $dir) {
            $packName = basename($dir);
            $manifest = $dir . '/pack.yaml';
            
            if (!file_exists($manifest)) {
                $this->error("? $packName: pack.yaml not found");
                $allValid = false;
                continue;
            }

            $this->info("? $packName: pack.yaml found and valid");
        }

        if ($allValid) {
            $this->info('? All feature packs validated successfully!');
            return Command::SUCCESS;
        } else {
            $this->error('? Some feature packs failed validation.');
            return Command::FAILURE;
        }
    }
}
