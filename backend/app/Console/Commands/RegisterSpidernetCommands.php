<?php

namespace App\Console\Commands;

use App\Console\Commands\ProjectionCheck;
use App\Console\Commands\SpidernetPackInstall;
use App\Console\Commands\SpidernetPackValidate;
use App\Console\Commands\ReplayDetectDivergence;
use App\Console\Commands\ReplayEvents;
use App\Console\Commands\VerifyEventChain;
use Illuminate\Console\Command;

class RegisterSpidernetCommands extends Command
{
    protected $signature = 'spidernet:commands:verify';
    protected $description = 'Verify SpiderNet command registration/discovery status';

    public function handle(): int
    {
        $commands = [
            ReplayEvents::class,
            VerifyEventChain::class,
            ProjectionCheck::class,
            ReplayDetectDivergence::class,
            SpidernetPackInstall::class,
            SpidernetPackValidate::class,
            // `php artisan inference:doctor` — ModelArk/DeepSeek end-to-end
            // preflight (PR 0). The class ships in the inference-doctor change
            // set; until it lands this line reports "missing" instead of throwing.
            \App\Console\Commands\InferenceDoctor::class,
            \App\Console\Commands\VoiceRenderPreviews::class,
            // Brain / Workspaces / Skills program (PR 1+). Each class ships in its
            // own change set; class_exists() below keeps this list additive.
            \App\Console\Commands\BrainSync::class,
            \App\Console\Commands\BrainGaps::class,
            \App\Console\Commands\BrainExport::class,
            \App\Console\Commands\BrainImport::class,
            \App\Console\Commands\SkillsSeed::class,
            \App\Console\Commands\SkillsValidate::class,
            \App\Console\Commands\SkillsInstall::class,
            \App\Console\Commands\SkillsEval::class,
            \App\Console\Commands\AgentsRun::class,
            \App\Console\Commands\AgentsRuns::class,
            \App\Console\Commands\AgentsDoctor::class,
            \App\Console\Commands\BriefToday::class,
        ];

        foreach ($commands as $commandClass) {
            if (! class_exists($commandClass)) {
                $this->warn("missing:    {$commandClass}");

                continue;
            }

            $this->line("registered: {$commandClass}");
        }

        $this->info('SpiderNet command classes are discoverable by Laravel command loader.');
        return self::SUCCESS;
    }
}
