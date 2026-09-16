<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentRun;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * php artisan agents:runs --tenant=hannah-ai --status=blocked,waiting_approval
 */
class AgentsRuns extends Command
{
    protected $signature = 'agents:runs
        {--tenant= : Tenant id or slug (omit for every tenant)}
        {--status= : Comma-separated statuses (queued, claimed, running, waiting_approval, waiting_input, blocked, succeeded, failed, cancelled)}
        {--skill= : Skill slug}
        {--limit=25 : Rows}';

    protected $description = 'List agent runs with status, cost and what they are waiting on';

    public function handle(): int
    {
        $query = AgentRun::query()->orderByDesc('created_at');

        $tenantRef = (string) $this->option('tenant');
        if ($tenantRef !== '') {
            $tenant = Str::isUuid($tenantRef) ? Tenant::find($tenantRef) : Tenant::where('slug', $tenantRef)->first();
            if ($tenant === null) {
                $this->error('Tenant not found.');

                return self::FAILURE;
            }
            $query->where('tenant_id', $tenant->id);
        }

        $status = (string) $this->option('status');
        if ($status !== '') {
            $query->whereIn('status', array_filter(array_map('trim', explode(',', $status))));
        }
        $skill = (string) $this->option('skill');
        if ($skill !== '') {
            $query->where('skill_slug', $skill);
        }

        $runs = $query->limit(max(1, (int) $this->option('limit')))->get();
        if ($runs->isEmpty()) {
            $this->line('No runs.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'skill', 'status', 'mode', 'trigger', 'cost', 'waiting on', 'created'],
            $runs->map(function (AgentRun $run) {
                $waiting = '';
                if ($run->status === AgentRun::STATUS_BLOCKED) {
                    $first = ($run->questions ?? [])[0] ?? [];
                    $waiting = Str::limit((string) ($first['question'] ?? ($first['path'] ?? 'brain gap')), 48);
                } elseif ($run->status === AgentRun::STATUS_WAITING_APPROVAL) {
                    $pending = ($run->state ?? [])['pending_tool_call'] ?? [];
                    $waiting = 'approval '.Str::limit((string) ($pending['approval_id'] ?? (($run->outputs ?? [])['approval_id'] ?? '')), 12, '');
                } elseif ($run->status === AgentRun::STATUS_FAILED) {
                    $waiting = Str::limit((string) $run->error, 48);
                } elseif ($run->status === AgentRun::STATUS_SUCCEEDED && ! empty(($run->outputs ?? [])['approval_id'])) {
                    $waiting = 'review '.Str::limit((string) $run->outputs['approval_id'], 12, '');
                }

                return [
                    $run->id,
                    $run->skill_slug,
                    $run->status,
                    $run->mode,
                    $run->trigger_type,
                    number_format((float) $run->cost_usd, 4),
                    $waiting,
                    $run->created_at?->diffForHumans() ?? '',
                ];
            })->all(),
        );

        return self::SUCCESS;
    }
}
