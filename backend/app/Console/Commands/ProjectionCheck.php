<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProjectionCheck extends Command
{
    protected $signature = 'events:projection-check {tenant : Tenant UUID}';

    protected $description = 'Run basic projection integrity checks against event stream';

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');

        $flowCreated = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'flow.created')
            ->count();

        $flowRows = DB::table('flows')->where('tenant_id', $tenantId)->count();

        $agentRegistered = Event::query()
            ->where('tenant_id', $tenantId)
            ->where('event_type', 'agent.registered')
            ->count();

        $agentRows = DB::table('agents')->where('tenant_id', $tenantId)->count();

        $this->line("Flows: events={$flowCreated}, projection_rows={$flowRows}");
        $this->line("Agents: events={$agentRegistered}, projection_rows={$agentRows}");

        $ok = ($flowRows <= $flowCreated) && ($agentRows <= $agentRegistered);

        if ($ok) {
            $this->info('Projection check passed.');

            return self::SUCCESS;
        }

        $this->error('Projection check failed. Replay may be required.');

        return self::FAILURE;
    }
}
