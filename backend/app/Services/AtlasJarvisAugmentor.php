<?php

namespace App\Services;

/**
 * Augments Atlas chat with OpenJarvis agent presets in the background.
 * Jarvis is never exposed to users — only Atlas surfaces merged results.
 */
class AtlasJarvisAugmentor
{
    /** Keywords that trigger deep research agent (Enterprise tier). */
    private const RESEARCH_PATTERNS = [
        '/\bresearch\b/i',
        '/\binvestigate\b/i',
        '/\bcitations?\b/i',
        '/\bcompare\b.+\b(options|plans|agents)\b/i',
    ];

    private const CODE_PATTERNS = [
        '/\bcode\b/i',
        '/\bscript\b/i',
        '/\bpython\b/i',
        '/\bintegration\b/i',
        '/\bapi\b/i',
    ];

    private const DIGEST_PATTERNS = [
        '/\bbriefing\b/i',
        '/\bdigest\b/i',
        '/\bweekly review\b/i',
        '/\bmorning\b/i',
    ];

    public function __construct(
        private readonly OpenJarvisGateway $gateway,
        private readonly AtlasIntentCompiler $intentCompiler,
        private readonly JarvisTierGate $tierGate,
        private readonly JarvisUsageRecorder $usageRecorder,
    ) {}

    public function shouldAugment(string $tenantId, string $message, ?string $plan = null): bool
    {
        if (! FeatureFlag::on('atlas.openjarvis', $tenantId)) {
            return false;
        }

        if (! $this->gateway->isEnabled()) {
            return false;
        }

        $agent = $this->resolveAgent($message, $this->intentCompiler->compile($message), $tenantId, $plan);

        return $this->tierGate->agentAllowed($tenantId, $agent, $plan);
    }

    /**
     * Resolve OpenJarvis agent from message + compiled intent (server-side only).
     */
    public function resolveAgent(string $message, array $compiledIntent, string $tenantId, ?string $plan = null): string
    {
        $intent = $compiledIntent['intent'] ?? 'chat';
        $candidate = 'simple';

        if ($intent === 'analyze_data') {
            $candidate = 'deep_research';
        } elseif (in_array($intent, ['create_flow', 'execute_flow'], true)) {
            $candidate = 'orchestrator';
        } else {
            foreach (self::RESEARCH_PATTERNS as $pattern) {
                if (preg_match($pattern, $message)) {
                    $candidate = 'deep_research';
                    break;
                }
            }

            if ($candidate === 'simple') {
                foreach (self::CODE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $message)) {
                        $candidate = 'code_assistant';
                        break;
                    }
                }
            }

            if ($candidate === 'simple') {
                foreach (self::DIGEST_PATTERNS as $pattern) {
                    if (preg_match($pattern, $message)) {
                        $candidate = 'morning_digest';
                        break;
                    }
                }
            }

            if ($candidate === 'simple' && ($compiledIntent['requires_planning'] ?? false) === true) {
                $candidate = 'orchestrator';
            }
        }

        if (! $this->tierGate->agentAllowed($tenantId, $candidate, $plan)) {
            if ($candidate === 'deep_research' && $this->tierGate->agentAllowed($tenantId, 'orchestrator', $plan)) {
                return 'orchestrator';
            }

            return 'simple';
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>|null Internal augmentation payload (never returned to clients)
     */
    public function augment(
        string $tenantId,
        string $userId,
        string $sessionId,
        string $message,
        ?string $plan = null,
    ): ?array {
        if (! $this->shouldAugment($tenantId, $message, $plan)) {
            return null;
        }

        $compiled = $this->intentCompiler->compile($message);
        $agent = $this->resolveAgent($message, $compiled, $tenantId, $plan);
        $skills = array_merge(
            ['spidernet-local-first-routing'],
            $this->tierGate->verticalSkillsForTenant($tenantId),
        );

        $result = $this->gateway->ask([
            'message' => $message,
            'agent' => $agent,
            'tenant_id' => $tenantId,
            'session_id' => $sessionId,
            'context' => [
                'user_id' => $userId,
                'compiled_intent' => $compiled,
                'surface' => 'atlas_background',
            ],
            'skills' => array_values(array_unique($skills)),
        ]);

        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $response = $result['response'] ?? [];
        $cost = (float) ($result['intelligence_per_watt']['estimated_cost_usd'] ?? 0);

        $this->usageRecorder->record($tenantId, $cost, $userId, [
            'agent' => $agent,
            'model' => $response['model'] ?? null,
            'local_first' => $result['intelligence_per_watt']['local_first'] ?? false,
        ]);

        return [
            'jarvis' => [
                'agent' => $agent,
                'spidernet_route' => $result['spidernet_route'] ?? null,
                'text' => $response['text'] ?? '',
                'source' => $response['source'] ?? 'unknown',
                'model' => $response['model'] ?? null,
                'intelligence_per_watt' => $result['intelligence_per_watt'] ?? [],
                'compiled_intent' => $compiled,
            ],
        ];
    }

    /**
     * Background operator briefing for outcomes weekly review (Growth+).
     *
     * @param  array<int, array<string, mixed>>  $pending
     * @param  array<int, array<string, mixed>>  $accepted
     */
    public function buildOperatorBriefing(
        string $tenantId,
        array $pending,
        array $accepted,
        array $autonomy,
        ?string $plan = null,
    ): ?array {
        if (! $this->tierGate->canMorningDigest($tenantId, $plan)) {
            return null;
        }

        $prompt = sprintf(
            "Generate a concise AIOS operator briefing for tenant %s.\n"
            ."Pending recommendations: %d\n"
            ."Accepted this period: %d\n"
            ."Autonomy level: %s\n"
            ."Top pending: %s\n"
            .'Format as actionable bullets for a 5-minute weekly review.',
            $tenantId,
            count($pending),
            count($accepted),
            (string) ($autonomy['autonomy_level'] ?? 1),
            implode('; ', array_map(fn ($r) => $r['title'] ?? 'Untitled', array_slice($pending, 0, 3))),
        );

        $skills = array_merge(
            ['spidernet-workflow-coordination'],
            $this->tierGate->verticalSkillsForTenant($tenantId),
        );

        $result = $this->gateway->ask([
            'message' => $prompt,
            'agent' => 'morning_digest',
            'tenant_id' => $tenantId,
            'context' => [
                'surface' => 'outcomes_weekly_review',
                'pending_count' => count($pending),
                'accepted_count' => count($accepted),
            ],
            'skills' => array_values(array_unique($skills)),
        ]);

        if (! ($result['ok'] ?? false)) {
            return null;
        }

        $response = $result['response'] ?? [];
        $cost = (float) ($result['intelligence_per_watt']['estimated_cost_usd'] ?? 0);
        $this->usageRecorder->record($tenantId, $cost, null, [
            'agent' => 'morning_digest',
            'surface' => 'outcomes_briefing',
        ]);

        return [
            'text' => $response['text'] ?? '',
            'estimated_cost_usd' => $cost,
        ];
    }
}
