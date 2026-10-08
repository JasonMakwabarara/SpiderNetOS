<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AgentArtifact;
use App\Models\AgentRun;
use App\Models\Tenant;
use App\Services\Agents\AgentRunService;
use App\Services\Agents\Exceptions\AgentRuntimeException;
use App\Services\MetaPlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * php artisan agents:run cold-email-drafting --tenant=hannah-ai --input=campaign="Spring" --input=segment="Ops leads" --sync
 *
 * Starts a run through MetaPlanner::dispatchRun (Hard Rule #2). --sync runs
 * the job inline in this process and prints outputs, artifacts and the
 * approval id; without it the run is queued on the `agents` queue.
 */
class AgentsRun extends Command
{
    protected $signature = 'agents:run
        {skill : Skill card slug (packages/skills/<slug>)}
        {--tenant= : Tenant id or slug}
        {--input=* : Inputs as key=value (JSON values accepted, e.g. --input=steps=3)}
        {--sync : Run the job inline in this process and wait for the result}
        {--trigger-ref= : Replay-idempotency key (a second call with the same ref returns the same run)}
        {--force : Bypass the agents.runtime flags for this process only}';

    protected $description = 'Start a skill run for a tenant on the PHP skill runtime and print its outcome';

    public function handle(AgentRunService $runs, MetaPlanner $planner): int
    {
        $tenant = $this->resolveTenant((string) $this->option('tenant'));
        if ($tenant === null) {
            $this->error('Tenant not found. Pass --tenant=<id|slug>.');

            return self::FAILURE;
        }

        if ($this->option('force')) {
            config(['agents.runtime_enabled' => true]);
            config(['features' => array_merge((array) config('features', []), ['agents.runtime' => 'on'])]);
            Cache::forget('featureflag:agents.runtime');
            Cache::forget('featureflag:agents.runtime:t:'.$tenant->id);
            $this->warn('--force: runtime flags bypassed for this process only.');
        }

        $skill = (string) $this->argument('skill');
        $inputs = $this->parseInputs((array) $this->option('input'));
        $triggerRef = $this->option('trigger-ref') ? (string) $this->option('trigger-ref') : null;

        try {
            if ($this->option('sync')) {
                $run = $runs->create((string) $tenant->id, $skill, $inputs, AgentRun::TRIGGER_MANUAL, $triggerRef, 'cli');
                if ($run->status === AgentRun::STATUS_QUEUED) {
                    $planner->dispatchRun($run, inline: true);
                }
                $run->refresh();
            } else {
                $run = $runs->start((string) $tenant->id, $skill, $inputs, AgentRun::TRIGGER_MANUAL, $triggerRef, 'cli');
            }
        } catch (AgentRuntimeException $e) {
            $this->error("[{$e->errorCode}] {$e->getMessage()}");
            foreach ($e->extra as $key => $value) {
                $this->line("  {$key}: ".(is_scalar($value) ? (string) $value : json_encode($value)));
            }

            return self::FAILURE;
        }

        $this->printRun($run);

        return in_array($run->status, [AgentRun::STATUS_FAILED, AgentRun::STATUS_CANCELLED], true) ? self::FAILURE : self::SUCCESS;
    }

    private function printRun(AgentRun $run): void
    {
        $this->info("Run {$run->id} — {$run->skill_slug} [{$run->status}]");
        $this->table(['field', 'value'], [
            ['workspace_id', (string) $run->workspace_id],
            ['agent_id', (string) $run->agent_id],
            ['mode', $run->mode],
            ['trigger', $run->trigger_type.($run->trigger_ref ? " ({$run->trigger_ref})" : '')],
            ['tokens', (string) $run->tokens],
            ['cost_usd', number_format((float) $run->cost_usd, 6)],
            ['error', (string) ($run->error ?? '')],
        ]);

        if (($run->questions ?? []) !== []) {
            $this->warn('Blocked — the brain needs answers:');
            foreach ((array) $run->questions as $q) {
                $this->line(sprintf('  • %s%s: %s', $q['path'] ?? '?', isset($q['section']) ? '#'.$q['section'] : '', $q['question'] ?? ($q['reason'] ?? '')));
            }
            $this->line("Answer with POST /api/agent-runs/{$run->id}/answers or fill the brain, then retry.");
        }

        $outputs = (array) ($run->outputs ?? []);
        if ($outputs !== []) {
            $this->line('');
            $this->info('Outputs');
            foreach (['summary', 'sequence_id', 'artifact_id', 'approval_id', 'campaign_key', 'step_count', 'model', 'prompt_version'] as $key) {
                if (isset($outputs[$key]) && $outputs[$key] !== '' && $outputs[$key] !== null) {
                    $this->line("  {$key}: ".(is_scalar($outputs[$key]) ? (string) $outputs[$key] : json_encode($outputs[$key])));
                }
            }
            foreach ((array) ($outputs['next_steps'] ?? []) as $step) {
                $this->line(sprintf('  next: [%s] %s → %s (%s)', $step['origin'] ?? 'card', $step['label'] ?? '', $step['skill'] ?? '', $step['when'] ?? ''));
            }
        }

        $artifacts = AgentArtifact::where('run_id', $run->id)->orderBy('created_at')->get();
        if ($artifacts->isNotEmpty()) {
            $this->line('');
            $this->info('Artifacts');
            $this->table(['id', 'kind', 'status', 'path', 'approval_id'], $artifacts->map(fn (AgentArtifact $a) => [
                $a->id, $a->kind, $a->status, (string) $a->path, (string) ($a->approval_id ?? ''),
            ])->all());
        }

        $pending = ($run->state ?? [])['pending_tool_call'] ?? null;
        if (is_array($pending)) {
            $this->warn(sprintf('Waiting for approval %s on tool %s.', $pending['approval_id'] ?? '?', $pending['tool'] ?? '?'));
        }
    }

    /** @param list<string> $pairs */
    private function parseInputs(array $pairs): array
    {
        $inputs = [];
        foreach ($pairs as $pair) {
            if (! str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $decoded = json_decode($value, true);
            $inputs[trim($key)] = json_last_error() === JSON_ERROR_NONE && ! is_string($decoded) ? $decoded : $value;
        }

        return $inputs;
    }

    private function resolveTenant(string $ref): ?Tenant
    {
        if ($ref === '') {
            return null;
        }

        return Str::isUuid($ref) ? Tenant::find($ref) : Tenant::where('slug', $ref)->first();
    }
}
