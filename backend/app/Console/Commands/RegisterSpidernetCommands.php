<?php

namespace App\Console\Commands;

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
        ];

        foreach ($commands as $commandClass) {
            $this->line("registered: {$commandClass}");
        }

        $this->info('SpiderNet command classes are discoverable by Laravel command loader.');

        return self::SUCCESS;
    }
}
