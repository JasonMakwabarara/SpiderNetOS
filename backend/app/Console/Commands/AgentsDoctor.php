<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Agents\AgentRunService;
use App\Services\Agents\Collaborators;
use App\Services\FeatureFlag;
use App\Services\Inference\InferencePlaneClient;
use App\Services\Tools\ToolCatalogue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;

/**
 * php artisan agents:doctor [--tenant=<id>]
 *
 * Preflight for the PHP skill runtime (run before enabling agents.* flags
 * for a tenant, the outreach:doctor pattern): flags, queue, Redis, Horizon
 * supervisor, skills root + registry, brain manifest, identities, the
 * runtime tables, approval hooks and the inference plane. Prints one
 * PASS/WARN/FAIL line per check; exit 1 on any FAIL.
 */
class AgentsDoctor extends Command
{
    protected $signature = 'agents:doctor {--tenant= : Tenant id to evaluate per-tenant flags for}';

    protected $description = 'Preflight checks for the PHP skill runtime (flags, queue, Redis, Horizon, skills, brain manifest, tables, hooks, inference)';

    private int $failures = 0;

    public function handle(): int
    {
        $tenantId = $this->option('tenant') ? (string) $this->option('tenant') : null;

        $this->section('Flags');
        $runtimeConfig = (bool) config('agents.runtime_enabled');
        $this->report($runtimeConfig ? 'PASS' : 'WARN', 'config agents.runtime_enabled (AGENTS_RUNTIME_ENABLED)', $runtimeConfig ? 'true' : 'false — runs will fail with runtime_disabled');
        foreach (['agents.runtime', 'agents.tools', 'agents.tools.send', 'agents.tools.irreversible', 'agents.heartbeat', 'agents.event_triggers', 'brain.enabled'] as $flag) {
            $on = FeatureFlag::on($flag, $tenantId);
            $level = $on ? 'PASS' : ($flag === 'brain.enabled' ? 'FAIL' : 'WARN');
            $this->report($level, "flag {$flag}".($tenantId ? " (tenant {$tenantId})" : ''), $on ? 'on' : 'off');
        }
        if ($tenantId !== null) {
            $enabled = AgentRunService::runtimeEnabled($tenantId);
            $this->report($enabled ? 'PASS' : 'WARN', 'runtime enabled for tenant', $enabled ? 'dispatchRun will enqueue' : 'both the config switch and agents.runtime must be on');
        }

        $this->section('Queue');
        $queueConnection = (string) config('queue.default');
        $queueName = (string) config('agents.queue', 'agents');
        $this->report($queueConnection === 'sync' ? 'WARN' : 'PASS', 'queue connection', $queueConnection.($queueConnection === 'sync' ? ' — runs execute inline in the web request' : ''));
        $this->report('PASS', 'agents queue name', $queueName);
        $this->report((int) config('agents.job_timeout_seconds') < 300 ? 'PASS' : 'WARN', 'job timeout < Horizon timeout 300', (string) config('agents.job_timeout_seconds'));

        $this->section('Redis');
        try {
            Redis::connection()->ping();
            $this->report('PASS', 'Redis reachable', 'ping ok (TenantRunSlot funnel, cost counters, flags)');
        } catch (\Throwable $e) {
            $this->report($queueConnection === 'redis' ? 'FAIL' : 'WARN', 'Redis reachable', 'no — '.$e->getMessage());
        }

        $this->section('Horizon');
        $supervisors = (array) config('horizon.defaults', []);
        $agentsSupervisor = null;
        foreach ($supervisors as $name => $def) {
            if (in_array($queueName, (array) ($def['queue'] ?? []), true)) {
                $agentsSupervisor = $name;
                break;
            }
        }
        $this->report($agentsSupervisor !== null ? 'PASS' : 'FAIL', 'Horizon supervisor consumes the agents queue', $agentsSupervisor ?? 'none found in config/horizon.php defaults');
        $env = (string) config('app.env');
        $envSup = (array) (config("horizon.environments.{$env}", [])[$agentsSupervisor ?? ''] ?? []);
        $this->report($envSup !== [] ? 'PASS' : 'WARN', "supervisor sized for env {$env}", $envSup !== [] ? 'maxProcesses='.($envSup['maxProcesses'] ?? '?') : 'no environment override');

        $this->section('Skills');
        $skillsRoot = (string) config('agents.skills_root');
        $this->report(is_dir($skillsRoot) ? 'PASS' : 'FAIL', 'skills root exists', $skillsRoot);
        $folders = is_dir($skillsRoot) ? array_filter(glob($skillsRoot.'/*/card.yaml') ?: []) : [];
        $this->report(count($folders) > 0 ? 'PASS' : 'WARN', 'card folders', (string) count($folders));
        $registry = Collaborators::skillRegistry();
        if ($registry === null) {
            $this->report('FAIL', 'SkillRegistry available', 'App\\Services\\Skills\\SkillRegistry missing');
        } else {
            try {
                $cards = method_exists($registry, 'all') ? (array) $registry->all() : [];
                $this->report(count($cards) > 0 ? 'PASS' : 'WARN', 'SkillRegistry loads cards', count($cards).' cards');
                if (method_exists($registry, 'validateAll')) {
                    $errors = (array) $registry->validateAll();
                    $bad = array_filter($errors, fn ($e) => $e !== []);
                    $this->report($bad === [] ? 'PASS' : 'FAIL', 'skills:validate', $bad === [] ? 'all cards valid' : count($bad).' cards with errors: '.implode(', ', array_keys($bad)));
                }
            } catch (\Throwable $e) {
                $this->report('FAIL', 'SkillRegistry loads cards', $e->getMessage());
            }
        }
        foreach ([Collaborators::SKILL_PROMPT_BUILDER, Collaborators::SKILL_OUTPUT_VALIDATOR, Collaborators::SKILL_INSTALLER] as $class) {
            $this->report(Collaborators::has($class) ? 'PASS' : 'FAIL', 'class '.$class, Collaborators::has($class) ? 'present' : 'missing');
        }
        $catalogue = app(ToolCatalogue::class);
        $this->report('PASS', 'tool catalogue', implode(', ', $catalogue->names()));
        if ($registry !== null && method_exists($registry, 'all')) {
            try {
                $unknown = [];
                foreach ((array) $registry->all() as $slug => $card) {
                    $tools = array_merge((array) ($card->tools ?? []), (array) ($card->postActions ?? []));
                    foreach ($tools as $tool) {
                        if (str_contains((string) $tool, '.') && ! $catalogue->has((string) $tool)) {
                            $unknown[] = "{$slug}:{$tool}";
                        }
                    }
                }
                $this->report($unknown === [] ? 'PASS' : 'WARN', 'card tools exist in the catalogue', $unknown === [] ? 'yes' : 'unknown: '.implode(', ', $unknown));
            } catch (\Throwable) {
                // covered above
            }
        }

        $this->section('Brain');
        $manifest = (string) config('agents.brain_manifest');
        $manifestOk = is_readable($manifest);
        $this->report($manifestOk ? 'PASS' : 'FAIL', 'brain manifest readable', $manifest);
        if ($manifestOk) {
            try {
                $parsed = (array) Yaml::parseFile($manifest);
                $this->report(isset($parsed['paths']) ? 'PASS' : 'FAIL', 'manifest parses', count((array) ($parsed['paths'] ?? [])).' paths, '.count((array) ($parsed['keys'] ?? [])).' keys');
            } catch (\Throwable $e) {
                $this->report('FAIL', 'manifest parses', $e->getMessage());
            }
        }
        $identities = (string) config('agents.identities_manifest');
        $this->report(is_readable($identities) ? 'PASS' : 'FAIL', 'identities manifest readable', $identities);
        foreach ([Collaborators::BRAIN_SNAPSHOT, Collaborators::BRAIN_GAP_ANALYZER, Collaborators::BRAIN_STORE, Collaborators::BRAIN_SYNC] as $class) {
            $this->report(Collaborators::has($class) ? 'PASS' : 'FAIL', 'class '.$class, Collaborators::has($class) ? 'present' : 'missing');
        }
        foreach ([Collaborators::REVISION_RECORDER, Collaborators::CIRCUIT_BREAKER] as $class) {
            $this->report(Collaborators::has($class) ? 'PASS' : 'WARN', 'class '.$class, Collaborators::has($class) ? 'present' : 'missing (optional in PR 1)');
        }

        $this->section('Tables');
        foreach (['agent_workspaces', 'tenant_skills', 'agent_runs', 'agent_run_steps', 'agent_artifacts', 'brain_files', 'brain_file_versions', 'skills', 'approvals', 'message_templates', 'business_assets', 'awareness_items'] as $table) {
            $exists = Schema::hasTable($table);
            $this->report($exists ? 'PASS' : ($table === 'awareness_items' ? 'WARN' : 'FAIL'), "table {$table}", $exists ? 'present' : 'missing — run migrations');
        }

        $this->section('Approval hooks');
        foreach ((array) config('approvals.resource_hooks', []) as $type => $hook) {
            $class = (array) $hook;
            $class = $class[0] ?? null;
            $ok = is_string($class) && Collaborators::has($class);
            $this->report($ok ? 'PASS' : 'WARN', "hook {$type}", (string) $class.($ok ? '' : ' — missing, hook will be skipped'));
        }

        $this->section('Inference plane');
        $client = app(InferencePlaneClient::class);
        $this->report($client->configured() ? 'PASS' : 'WARN', 'INFERENCE_URL configured', (string) config('services.inference.url'));

        $this->line('');
        if ($this->failures > 0) {
            $this->error("{$this->failures} check(s) failed.");

            return self::FAILURE;
        }
        $this->info('Agent runtime preflight passed.');

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("<options=bold>{$title}</>");
    }

    private function report(string $level, string $check, string $detail = ''): void
    {
        if ($level === 'FAIL') {
            $this->failures++;
        }
        $tag = match ($level) {
            'PASS' => '<fg=green>PASS</>',
            'WARN' => '<fg=yellow>WARN</>',
            default => '<fg=red>FAIL</>',
        };
        $this->line(sprintf('  %s  %s%s', $tag, $check, $detail !== '' ? ' — '.$detail : ''));
    }
}
