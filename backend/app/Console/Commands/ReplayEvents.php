<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\EventStore;
use App\Services\Projections\AgentProjection;
use App\Services\Projections\FlowProjection;
use App\Services\Projections\UsageProjection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReplayEvents extends Command
{
    protected $signature = 'events:replay {tenant : Tenant UUID} {--projection= : Optional projection to rebuild}';
    protected $description = 'Replay tenant event stream and rebuild projections deterministically';

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant');
        $projection = $this->option('projection');

        $events = Event::forTenantOrdered($tenantId)->get();

        if ($events->isEmpty()) {
            $this->warn("No events found for tenant {$tenantId}");
            return self::SUCCESS;
        }

        $this->info("Replaying {$events->count()} events for tenant {$tenantId}");

        DB::transaction(function () use ($events, $projection, $tenantId) {
            if (!$projection || $projection === 'agents') {
                DB::table('agents')->where('tenant_id', $tenantId)->delete();
            }
            if (!$projection || $projection === 'flows') {
                DB::table('flows')->where('tenant_id', $tenantId)->delete();
                DB::table('dag_nodes')->where('flow_id', 'in', function ($q) use ($tenantId) {
                    $q->select('id')->from('flows')->where('tenant_id', $tenantId);
                })->delete();
            }

            $agentProjection = app(AgentProjection::class);
            $flowProjection = app(FlowProjection::class);
            $usageProjection = app(UsageProjection::class);

            foreach ($events as $event) {
                if ((!$projection || $projection === 'agents') && $agentProjection->accepts($event)) {
                    $agentProjection->handle($event);
                }
                if ((!$projection || $projection === 'flows') && $flowProjection->accepts($event)) {
                    $flowProjection->handle($event);
                }
                if ((!$projection || $projection === 'usage') && $usageProjection->accepts($event)) {
                    $usageProjection->handle($event);
                }
            }
        });

        $this->info('Replay completed.');
        return self::SUCCESS;
    }
}
