<?php

declare(strict_types=1);

namespace App\Services\Skills;

use App\Models\Agent;
use App\Models\AgentWorkspace;
use App\Models\TenantSkill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Provided when needed" (plan D5): enabling a catalogue skill for a tenant
 * resolves its identity through identities.yaml to an `agents` row (created
 * and activated when the identity is planned and no row exists, using the
 * pack's prompts/<agent>.md when present), makes sure the identity's
 * persistent workspace exists (plan D3), and writes the tenant_skills row.
 * Idempotent: re-enabling never resets the autonomy level or the counters.
 */
class SkillInstaller
{
    public function __construct(private readonly SkillRegistry $registry) {}

    public function installForTenant(string $tenantId, string $slug, ?string $agentSlug = null, string $installedFrom = 'catalogue'): TenantSkill
    {
        $card = $this->registry->get($slug);
        if ($card === null) {
            throw new \InvalidArgumentException("Unknown skill \"{$slug}\".");
        }
        $identity = $this->registry->identityFor($card);

        return DB::transaction(function () use ($tenantId, $card, $identity, $agentSlug, $installedFrom): TenantSkill {
            $agent = $this->ensureAgent($tenantId, $card, $identity, $agentSlug);
            $workspace = $agent && ! empty($identity['workspace'] ?? true)
                ? $this->ensureWorkspace($tenantId, $agent, (string) $identity['key'], $card)
                : null;

            /** @var TenantSkill $row */
            $row = TenantSkill::firstOrNew(['tenant_id' => $tenantId, 'skill_slug' => $card->id]);
            $isNew = ! $row->exists;
            $state = (array) ($row->state ?? []);
            $state['installed_from'] = $installedFrom;
            $state['identity'] = (string) $identity['key'];
            $state['card_version'] = $card->version;

            $row->fill([
                'agent_id' => $agent?->id,
                'workspace_id' => $workspace?->id,
                'enabled' => true,
                'state' => $state,
                'enabled_at' => $row->enabled_at ?? now(),
            ]);
            if ($isNew) {
                $row->autonomy_level = $card->autonomy['default'];
                $row->budget_daily_usd = $card->costBudget['daily_limit_usd'] ?? null;
                $row->clean_drafts_count = 0;
            }
            $row->save();

            return $row->refresh();
        });
    }

    public function uninstall(string $tenantId, string $slug): void
    {
        $row = TenantSkill::forTenant($tenantId)->where('skill_slug', $slug)->first();
        if ($row === null) {
            return;
        }
        $state = (array) ($row->state ?? []);
        $state['disabled_at'] = now()->toIso8601String();
        $row->fill(['enabled' => false, 'state' => $state])->save();
    }

    public function setAutonomy(string $tenantId, string $slug, string $level): TenantSkill
    {
        $card = $this->registry->get($slug);
        if ($card === null) {
            throw new \InvalidArgumentException("Unknown skill \"{$slug}\".");
        }
        if (! in_array($level, $card->autonomy['ladder'], true) && $level !== TenantSkill::AUTONOMY_SHADOW) {
            throw new \InvalidArgumentException("Level \"{$level}\" is not on the ladder for {$slug} (".implode(', ', $card->autonomy['ladder']).').');
        }

        $row = TenantSkill::forTenant($tenantId)->where('skill_slug', $slug)->first()
            ?? $this->installForTenant($tenantId, $slug, null, 'pipeline');

        $row->autonomy_level = $level;
        $row->save();

        return $row->refresh();
    }

    /**
     * The `agents` row the identity runs as. Null for service identities
     * (agents_slug: null) — those never get a workspace.
     *
     * @param  array<string, mixed>  $identity
     */
    public function ensureAgent(string $tenantId, SkillCard $card, array $identity, ?string $agentSlug = null): ?Agent
    {
        $slug = $agentSlug ?? ($identity['agents_slug'] ?? null);
        if ($slug === null || $slug === '') {
            return null;
        }
        $slug = (string) $slug;

        /** @var Agent|null $agent */
        $agent = Agent::where('tenant_id', $tenantId)->where('slug', $slug)->first();
        $prompt = $this->packPrompt($identity['pack'] ?? $card->packId, (string) $identity['key']);

        if ($agent === null) {
            $config = array_filter([
                'pack_id' => $identity['pack'] ?? $card->packId,
                'identity' => (string) $identity['key'],
                'reports_to' => (string) ($identity['reports_to'] ?? $card->coreAgent),
                'system_prompt' => $prompt,
                'skills' => [$card->id],
                'provisioned_by' => 'skill_installer',
            ], fn ($v) => $v !== null && $v !== '');

            return Agent::create([
                'tenant_id' => $tenantId,
                'name' => (string) ($identity['display_name'] ?? Str::headline((string) $identity['key'])),
                'slug' => $slug,
                'description' => "Runs ".$card->displayName.' (identity '.$identity['key'].', reports to '.($identity['reports_to'] ?? $card->coreAgent).')',
                'type' => ($identity['pack'] ?? null) ? 'dynamic' : 'core',
                'status' => 'active',
                'capabilities' => [],
                'config' => $config,
                'activated_at' => now(),
            ]);
        }

        $config = (array) ($agent->config ?? []);
        $skills = array_values(array_unique(array_merge((array) ($config['skills'] ?? []), [$card->id])));
        $config['skills'] = $skills;
        $config['identity'] = $config['identity'] ?? (string) $identity['key'];
        if (empty($config['system_prompt']) && $prompt !== null) {
            $config['system_prompt'] = $prompt;
        }
        $changes = ['config' => $config];
        if ($agent->status !== 'active') {
            $changes['status'] = 'active';
            $changes['activated_at'] = $agent->activated_at ?? now();
        }
        $agent->fill($changes)->save();

        return $agent->refresh();
    }

    /** One persistent workspace per tenant × identity (plan D3). */
    public function ensureWorkspace(string $tenantId, Agent $agent, string $identityKey, ?SkillCard $card = null): AgentWorkspace
    {
        /** @var AgentWorkspace|null $workspace */
        $workspace = AgentWorkspace::where('tenant_id', $tenantId)->where('agent_id', $agent->id)->first();
        if ($workspace !== null) {
            if ($card !== null) {
                $pins = array_values(array_unique(array_merge((array) ($workspace->pinned_brain_paths ?? []), $card->requiredPaths())));
                if ($pins !== (array) ($workspace->pinned_brain_paths ?? [])) {
                    $workspace->fill(['pinned_brain_paths' => $pins])->save();
                }
            }

            return $workspace;
        }

        return AgentWorkspace::create([
            'tenant_id' => $tenantId,
            'agent_id' => $agent->id,
            'slug' => $identityKey,
            'status' => AgentWorkspace::STATUS_IDLE,
            'pinned_brain_paths' => $card ? $card->requiredPaths() : [],
            'scratch' => [],
            'drafts_root' => AgentWorkspace::defaultDraftsRoot($identityKey),
            'budget_daily_usd' => (float) config('agents.default_daily_budget_usd', 2.0),
            'spent_today_usd' => 0,
            'settings' => [],
        ]);
    }

    /**
     * packages/feature-packs/<pack>/prompts/<identity>.md when the pack ships one
     * (same hyphen/underscore tolerance as FeaturePackInstaller::packAgentPrompt()).
     */
    private function packPrompt(?string $packId, string $identityKey): ?string
    {
        if ($packId === null || $packId === '') {
            return null;
        }
        $roots = [
            storage_path('app/feature-packs/'.$packId),
            rtrim((string) env('FEATURE_PACKS_ROOT', dirname(base_path()).'/packages/feature-packs'), '/').'/'.$packId,
        ];
        $candidates = array_unique([$identityKey, Str::slug($identityKey), Str::slug($identityKey, '_')]);
        foreach ($roots as $root) {
            foreach ($candidates as $candidate) {
                $path = $root.'/prompts/'.$candidate.'.md';
                if (is_readable($path)) {
                    $contents = trim((string) file_get_contents($path));
                    if ($contents !== '') {
                        return $contents;
                    }
                }
            }
        }

        return null;
    }
}
