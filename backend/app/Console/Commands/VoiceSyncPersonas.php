<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\VoicePersonaSeeder;
use Illuminate\Console\Command;

/**
 * Projects inference/voice_personas.yaml into voice_personas (plan D7 §7).
 * Idempotent upsert by slug; Azure stays out unless --include-azure.
 */
class VoiceSyncPersonas extends Command
{
    protected $signature = 'voice:sync-personas
        {--include-azure : Also seed Azure personas (disabled by default — Jason rejected the Azure voices)}
        {--path= : Catalogue path (defaults to voice.personas_path, then {services.inference.path}/voice_personas.yaml)}';

    protected $description = 'Seed the Atlas voice personas from inference/voice_personas.yaml';

    public function handle(): int
    {
        try {
            $summary = (new VoicePersonaSeeder)->sync(
                $this->option('path') ?: null,
                (bool) $this->option('include-azure'),
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'voice personas from %s: %d created, %d updated, %d skipped, %d deactivated; %d active; default %s',
            $summary['path'], $summary['created'], $summary['updated'], $summary['skipped'], $summary['deactivated'],
            $summary['active'], $summary['default'] ?? 'none (set VOICE_DEFAULT_PERSONA or choose one in Settings → Voice)',
        ));

        return self::SUCCESS;
    }
}
