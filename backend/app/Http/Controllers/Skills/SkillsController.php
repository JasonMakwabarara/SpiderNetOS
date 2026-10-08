<?php

declare(strict_types=1);

namespace App\Http\Controllers\Skills;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantSkill;
use App\Services\PackGrowthService;
use App\Services\Skills\SkillCatalogue;
use App\Services\Skills\SkillInstaller;
use App\Services\Skills\SkillRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/skills — the catalogue, the full-screen card, enable, the autonomy
 * ladder, feedback and fire-and-forget signals (plan D5). Running a skill is
 * App\Http\Controllers\Agents\SkillRunController (the runtime stream).
 */
class SkillsController extends Controller
{
    public function __construct(
        private readonly SkillRegistry $registry,
        private readonly SkillCatalogue $catalogue,
        private readonly SkillInstaller $installer,
    ) {}

    /** GET /api/skills?pillar=&q=&enabled= */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $validated = $request->validate([
            'pillar' => ['nullable', 'string', Rule::in($this->registry->pillars())],
            'q' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'in:0,1,true,false'],
        ]);

        $entries = $this->catalogue->forTenant($tenantId);

        if (! empty($validated['pillar'])) {
            $entries = array_values(array_filter($entries, fn (array $e) => $e['pillar'] === $validated['pillar']));
        }
        if (isset($validated['enabled']) && $validated['enabled'] !== null && $validated['enabled'] !== '') {
            $wanted = in_array($validated['enabled'], ['1', 'true', true], true);
            $entries = array_values(array_filter($entries, fn (array $e) => $e['enabled'] === $wanted));
        }
        if (! empty($validated['q'])) {
            $q = mb_strtolower(trim($validated['q']));
            $entries = array_values(array_filter($entries, function (array $e) use ($q): bool {
                $haystack = mb_strtolower(implode(' ', array_merge(
                    [$e['slug'], $e['name'], $e['map_node'], $e['at_a_glance'], $e['core_agent'], $e['runs_on']],
                    $e['intent_keywords'],
                    $e['tags'],
                )));

                return str_contains($haystack, $q);
            }));
        }

        $identities = [];
        foreach ((array) ($this->registry->identities()['identities'] ?? []) as $key => $identity) {
            $identities[] = [
                'key' => $key,
                'display_name' => (string) ($identity['display_name'] ?? $key),
                'agents_slug' => $identity['agents_slug'] ?? null,
                'status' => (string) ($identity['status'] ?? 'existing'),
                'reports_to' => (string) ($identity['reports_to'] ?? 'atlas'),
                'pack' => $identity['pack'] ?? null,
                'skills' => array_values((array) ($identity['skills'] ?? [])),
            ];
        }
        $coreAgents = [];
        foreach ($this->registry->coreCharacterMeta() as $slug => $meta) {
            $coreAgents[] = ['slug' => $slug, 'display_name' => (string) ($meta['display_name'] ?? ucfirst((string) $slug)), 'role' => (string) ($meta['role'] ?? '')];
        }

        return response()->json([
            'data' => $entries,
            'meta' => [
                'pillars' => $this->registry->pillarsMeta(),
                'identities' => $identities,
                'core_agents' => $coreAgents,
                'total' => count($this->registry->all()),
            ],
        ]);
    }

    /** GET /api/skills/{slug} */
    public function show(Request $request, string $slug): JsonResponse
    {
        if (! $this->registry->has($slug)) {
            return response()->json(['message' => "Unknown skill \"{$slug}\"."], 404);
        }

        return response()->json(['data' => $this->catalogue->card((string) $request->attributes->get('tenant_id'), $slug)]);
    }

    /** POST /api/skills/{slug}/enable */
    public function enable(Request $request, string $slug): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $card = $this->registry->get($slug);
        if ($card === null) {
            return response()->json(['message' => "Unknown skill \"{$slug}\"."], 404);
        }

        $entitlement = $this->catalogue->entitlement($tenantId, $card);
        if (! $entitlement['entitled']) {
            return response()->json([
                'message' => 'This skill is provided by a feature pack that is not installed for your workspace.',
                'pack_id' => $entitlement['pack_id'],
                'checkout_hint' => $entitlement['checkout_hint'],
            ], 402);
        }

        $validated = $request->validate(['agent_slug' => ['nullable', 'string', 'max:64']]);
        $row = $this->installer->installForTenant($tenantId, $slug, $validated['agent_slug'] ?? null, 'catalogue');

        return response()->json([
            'data' => $this->catalogue->card($tenantId, $slug),
            'tenant_skill' => [
                'enabled' => (bool) $row->enabled,
                'autonomy_level' => $row->autonomy_level,
                'agent_id' => $row->agent_id,
                'workspace_id' => $row->workspace_id,
            ],
        ]);
    }

    /** PUT /api/skills/{slug}/pipeline {stage} */
    public function pipeline(Request $request, string $slug): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $card = $this->registry->get($slug);
        if ($card === null) {
            return response()->json(['message' => "Unknown skill \"{$slug}\"."], 404);
        }

        $validated = $request->validate([
            'stage' => ['required', 'string', Rule::in(TenantSkill::LADDER)],
        ]);
        $stage = $validated['stage'];

        if (! in_array($stage, $card->autonomy['ladder'], true)) {
            return response()->json([
                'message' => "\"{$card->displayName}\" cannot be set to {$stage}; its ladder is ".implode(' → ', $card->autonomy['ladder']).'.',
                'errors' => ['stage' => ['not on this skill\'s ladder']],
            ], 422);
        }

        if ($stage === TenantSkill::AUTONOMY_AUTONOMOUS) {
            /** @var Tenant|null $tenant */
            $tenant = $request->attributes->get('tenant') ?? Tenant::find($tenantId);
            $tenantLevel = (string) ($tenant?->automation_level ?? 'manual');
            if ($tenantLevel === 'manual') {
                return response()->json([
                    'message' => 'Your workspace automation level is manual; raise it in Settings before any skill can run autonomously.',
                    'errors' => ['stage' => ['tenant automation_level is manual']],
                ], 422);
            }
        }

        $entitlement = $this->catalogue->entitlement($tenantId, $card);
        if (! $entitlement['entitled']) {
            return response()->json(['message' => 'Install the pack before changing this skill\'s pipeline.', 'pack_id' => $entitlement['pack_id'], 'checkout_hint' => $entitlement['checkout_hint']], 402);
        }

        $row = $this->installer->setAutonomy($tenantId, $slug, $stage);

        return response()->json([
            'data' => $this->catalogue->card($tenantId, $slug)['pipeline'],
            'tenant_skill' => ['enabled' => (bool) $row->enabled, 'autonomy_level' => $row->autonomy_level],
        ]);
    }

    /** POST /api/skills/{slug}/feedback {sentiment, outcome} */
    public function feedback(Request $request, string $slug, PackGrowthService $growth): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $card = $this->registry->get($slug);
        if ($card === null) {
            return response()->json(['message' => "Unknown skill \"{$slug}\"."], 404);
        }
        $validated = $request->validate([
            'sentiment' => ['required', 'string', 'in:positive,negative,neutral'],
            'outcome' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $weight = $validated['sentiment'] === 'positive' ? 3 : ($validated['sentiment'] === 'negative' ? 4 : 1);
        $growth->recordSignal($tenantId, 'skill_feedback_'.$validated['sentiment'], $card->packId, array_filter([
            'skill' => $slug,
            'outcome' => $validated['outcome'] ?? null,
            'note' => $validated['note'] ?? null,
            'user_id' => $request->user()?->id,
        ]), $weight);

        return response()->json(['recorded' => true, 'skill' => $slug, 'sentiment' => $validated['sentiment']]);
    }

    /** POST /api/skills/signals {type, slug} — fire-and-forget */
    public function signals(Request $request, PackGrowthService $growth): JsonResponse
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $validated = $request->validate([
            'type' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,40}$/'],
            'slug' => ['required', 'string', 'max:64'],
            'context' => ['nullable', 'array'],
        ]);
        $card = $this->registry->get($validated['slug']);

        try {
            $growth->recordSignalThrottled(
                $tenantId,
                'skill_'.$validated['type'],
                $card?->packId,
                ['skill' => $validated['slug']] + (array) ($validated['context'] ?? []),
                1,
                300,
            );
        } catch (\Throwable) {
            // fire-and-forget: a signal never fails a request
        }

        return response()->json(['accepted' => true], 202);
    }
}
