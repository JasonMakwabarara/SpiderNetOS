<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SpidernetPackInstall extends Command
{
    protected $signature = 'spidernet:pack-install 
                            {pack_id : Pack directory name under packages/feature-packs/, e.g. real-estate-crm}
                            {--force : Replace existing staged copy in storage/app/feature-packs}';

    protected $description = 'Stage Feature Pack artefacts into storage/app/feature-packs/{id} from packages/feature-packs/{id}.';

    public function handle(): int
    {
        $id = strtolower((string) $this->argument('pack_id'));
        if ($id === '') {
            $this->error('pack_id is required.');

            return self::FAILURE;
        }

        $repoRoot = dirname(base_path());
        $src = $repoRoot.'/packages/feature-packs/'.$id;

        if (! is_dir($src)) {
            $this->error("Source pack directory not found: {$src}");

            return self::FAILURE;
        }

        $manifest = $src.'/pack.yaml';
        if (! is_readable($manifest)) {
            $this->error("Missing pack.yaml in {$src}");

            return self::FAILURE;
        }

        $destination = storage_path('app/feature-packs/'.$id);
        if (File::isDirectory($destination) && ! $this->option('force')) {
            $this->error("Destination exists: {$destination} (pass --force to replace)");

            return self::FAILURE;
        }

        if (File::isDirectory($destination)) {
            File::deleteDirectory($destination);
        }

        File::ensureDirectoryExists($destination);
        File::copyDirectory($src, $destination);

        $this->info("Copied pack {$id} to {$destination}");
        $this->line('Dynamic agent registration against the tenants table remains part of provisioning; inspect pack spec under docs/feature-packs/SPEC.md.');

        return self::SUCCESS;
    }
}
