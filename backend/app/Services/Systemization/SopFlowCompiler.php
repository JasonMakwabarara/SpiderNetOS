<?php

declare(strict_types=1);

namespace App\Services\Systemization;

use App\Models\BusinessProcess;
use App\Models\Flow;
use App\Models\Sop;
use Illuminate\Support\Str;

/**
 * Compiles a published SOP into an executable Flow (the process's runbook).
 *
 * Mapping:
 *   SOP trigger           → trigger node + optional schedule_cron on the flow
 *   SOP steps             → one action node each, carrying the full instruction,
 *                           the owning agent, and the required tools
 *   SOP quality_criteria  → final report node config, so every execution ends
 *                           by checking "how do we know it was done right?"
 *
 * Steps currently execute through the deterministic NodeActionRunner
 * (action: log) so the loop runs end-to-end offline; when a step should be
 * performed by the owning agent's LLM runtime, flip its node type to an
 * agent type and MetaPlanner dispatch takes over — the config contract
 * (instruction / tools / agent_id) is already what dispatch expects.
 */
class SopFlowCompiler
{
    public const SCHEDULES = ['manual', 'daily_morning', 'weekday_morning', 'hourly'];

    /**
     * Create or update the Flow for a process from its published SOP.
     */
    public function compile(BusinessProcess $process, Sop $sop, string $schedule = 'manual'): Flow
    {
        if (! in_array($schedule, self::SCHEDULES, true)) {
            throw new \InvalidArgumentException("Unknown schedule [{$schedule}].");
        }

        $steps = array_values((array) $sop->steps);
        $nodes = [
            [
                'id' => 'trigger',
                'type' => 'trigger',
                'label' => 'Trigger',
                'config' => [
                    'when' => $sop->trigger,
                    'sop_id' => (string) $sop->id,
                    'sop_version' => $sop->version,
                ],
            ],
        ];
        $edges = [];
        $previous = 'trigger';

        foreach ($steps as $i => $step) {
            $nodeId = 'step_' . ($i + 1);
            $nodes[] = [
                'id' => $nodeId,
                'type' => 'action',
                'label' => Str::limit((string) $step, 60),
                'config' => [
                    'action' => 'log',
                    'instruction' => (string) $step,
                    'tools' => $sop->tools ?? [],
                    'agent_id' => $process->owner_agent_id,
                    'sop_step' => $i + 1,
                ],
            ];
            $edges[] = ['from' => $previous, 'to' => $nodeId];
            $previous = $nodeId;
        }

        $nodes[] = [
            'id' => 'report',
            'type' => 'action',
            'label' => 'Report outcome',
            'config' => [
                'action' => 'notify',
                'channel' => 'in_app',
                'body' => "SOP \"{$sop->title}\" v{$sop->version} run finished for process \"{$process->name}\".",
                'quality_criteria' => $sop->quality_criteria ?? [],
                'process_id' => (string) $process->id,
            ],
        ];
        $edges[] = ['from' => $previous, 'to' => 'report'];

        $definition = [
            'name' => 'SOP: ' . $sop->title,
            'description' => "Runbook compiled from SOP v{$sop->version} of process \"{$process->name}\". Goal: " . ($process->goal ?: $sop->purpose),
            'dag' => ['nodes' => $nodes, 'edges' => $edges],
            'triggers' => [
                'type' => $schedule === 'manual' ? 'manual' : 'schedule',
                'context' => [
                    'process_id' => (string) $process->id,
                    'sop_id' => (string) $sop->id,
                    'sop_version' => $sop->version,
                    'owner_agent_id' => $process->owner_agent_id,
                    'quality_criteria' => $sop->quality_criteria ?? [],
                ],
            ],
            'status' => 'published',
            'published_at' => now(),
        ];

        $flow = $process->flow_id ? Flow::query()->where('tenant_id', $process->tenant_id)->find($process->flow_id) : null;

        if ($flow) {
            $flow->fill($definition)->save();
        } else {
            $flow = Flow::create([
                'tenant_id' => $process->tenant_id,
                'slug' => Str::slug('sop-' . Str::limit($sop->title, 40, '') . '-' . substr((string) Str::uuid(), 0, 8)),
                ...$definition,
            ]);
        }

        // schedule_cron / schedule_timezone are plain columns (not fillable)
        // consumed by DispatchScheduledFlowsJob every minute.
        $flow->forceFill([
            'schedule_cron' => $schedule === 'manual' ? null : $schedule,
            'schedule_timezone' => 'UTC',
        ])->save();

        return $flow;
    }
}
