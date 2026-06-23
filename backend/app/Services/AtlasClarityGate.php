<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Trust/clarity gate: ask when unsure, confirm before consequential actions.
 * Calibrated to tenant automation_level and earned trust per intent type.
 */
class AtlasClarityGate
{
    private const TRUST_CONFIRM_THRESHOLD = 3;

    private const LOW_CONFIDENCE_THRESHOLD = 0.55;

    /** @var list<string> */
    private const ACTIONABLE_PATTERNS = [
        'create', 'build', 'run', 'execute', 'schedule', 'automate',
        'set up', 'setup', 'deploy', 'install', 'trigger', 'start',
        'publish', 'launch', 'enable',
    ];

    /** @var list<string> */
    private const IRREVERSIBLE_PATTERNS = [
        'delete', 'remove', 'purge', 'wipe', 'drop', 'destroy',
        'pay', 'transfer', 'send payment', 'send ', 'cancel all', 'terminate',
        'refund', 'charge',
    ];

    /** @var list<string> */
    private const AMBIGUOUS_PATTERNS = [
        'run it', 'do it', 'do that', 'execute this', 'execute it',
        'that one', 'the same', 'again', 'go ahead', 'yes', 'ok',
    ];

    public function __construct(
        private readonly MetaPlanner $metaPlanner,
        private readonly AtlasIntentCompiler $intentCompiler,
    ) {}

    /**
     * @return array{
     *   mode: string,
     *   question?: string,
     *   questions?: array<int, string>,
     *   pending_action?: array<string, mixed>,
     *   consequence?: array<string, mixed>,
     *   confidence?: float|null
     * }
     */
    public function assess(string $tenantId, string $message, string $automationLevel): array
    {
        $level = in_array($automationLevel, ['manual', 'assisted', 'autonomous'], true)
            ? $automationLevel
            : 'assisted';

        $consequence = $this->classifyConsequence($message);
        $confidence = $this->resolveConfidence($message);
        $consequence['confidence'] = $confidence;

        if ($consequence['ambiguous']) {
            $question = $this->clarifyingQuestion($message, $consequence);

            return [
                'mode' => 'clarify',
                'question' => $question,
                'questions' => [$question],
                'consequence' => $consequence,
                'confidence' => $confidence,
            ];
        }

        if ($consequence['irreversible']) {
            return $this->buildConfirmAssessment($message, $consequence, $level);
        }

        if ($level === 'manual' && $consequence['actionable']) {
            if (! $this->trustEarned($tenantId, $consequence['intent'])) {
                return $this->buildConfirmAssessment($message, $consequence, $level);
            }
        }

        if ($level === 'assisted') {
            if ($confidence !== null && $confidence < self::LOW_CONFIDENCE_THRESHOLD) {
                $question = $this->clarifyingQuestion($message, $consequence);

                return [
                    'mode' => 'clarify',
                    'question' => $question,
                    'questions' => [$question],
                    'consequence' => $consequence,
                    'confidence' => $confidence,
                ];
            }
        }

        if ($level === 'autonomous' && $consequence['actionable'] && $consequence['intent'] === 'unknown_action') {
            $question = $this->clarifyingQuestion($message, $consequence);

            return [
                'mode' => 'clarify',
                'question' => $question,
                'questions' => [$question],
                'consequence' => $consequence,
                'confidence' => $confidence,
            ];
        }

        return [
            'mode' => 'act',
            'consequence' => $consequence,
            'confidence' => $confidence,
        ];
    }

    public function recordTrustConfirmation(string $tenantId, string $intent): void
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        $learned = $this->decodeJson($row->learned_signals ?? null);
        $trust = (array) ($learned['trust'] ?? []);
        $byIntent = (array) ($trust['confirmed_by_intent'] ?? []);

        $key = $intent !== '' ? $intent : 'automation';
        $byIntent[$key] = (int) ($byIntent[$key] ?? 0) + 1;
        $trust['confirmed_by_intent'] = $byIntent;
        $trust['total_confirmed'] = (int) ($trust['total_confirmed'] ?? 0) + 1;
        $trust['last_confirmed_at'] = now()->toIso8601String();
        $learned['trust'] = $trust;

        if ($row) {
            DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update([
                'learned_signals' => json_encode($learned),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('tenant_business_profiles')->insert([
                'tenant_id' => $tenantId,
                'learned_signals' => json_encode($learned),
                'discovery_complete_pct' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function recordRefinementSignal(string $tenantId, string $signalType, array $context = []): void
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        $learned = $this->decodeJson($row->learned_signals ?? null);
        $refinement = (array) ($learned['refinement'] ?? []);
        $events = (array) ($refinement['events'] ?? []);
        $events[] = array_merge(['type' => $signalType, 'at' => now()->toIso8601String()], $context);
        $refinement['events'] = array_slice($events, -50);
        $learned['refinement'] = $refinement;

        if ($row) {
            DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->update([
                'learned_signals' => json_encode($learned),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return array{actionable: bool, irreversible: bool, intent: string, ambiguous: bool, summary: string, ast_type: string}
     */
    public function classifyConsequence(string $message): array
    {
        $lower = strtolower(trim($message));
        $ast = $this->metaPlanner->parseCommandToAst($message);
        $astType = (string) ($ast['type'] ?? 'chat');

        $actionable = $astType !== 'chat'
            || $this->matchesAny($lower, self::ACTIONABLE_PATTERNS);

        $irreversible = $this->matchesAny($lower, self::IRREVERSIBLE_PATTERNS);

        $ambiguous = $this->isAmbiguous($lower, $actionable, $astType);

        $intent = match ($astType) {
            'create_flow' => 'create_flow',
            'execute' => 'execute',
            'query_status' => 'query_status',
            default => $actionable ? ($irreversible ? 'irreversible_action' : 'automation') : 'chat',
        };

        if ($actionable && $intent === 'automation' && ! $irreversible && $astType === 'chat') {
            $intent = 'unknown_action';
        }

        return [
            'actionable' => $actionable,
            'irreversible' => $irreversible,
            'intent' => $intent,
            'ambiguous' => $ambiguous,
            'summary' => $this->summarizeAction($message, $ast),
            'ast_type' => $astType,
        ];
    }

    /**
     * @param array<string, mixed> $consequence
     */
    public function clarifyingQuestion(string $message, array $consequence): string
    {
        $snippet = Str::limit(trim($message), 80);

        if ($consequence['ambiguous'] ?? false) {
            return "I want to get this right — when you said \"{$snippet}\", which specific task or flow did you mean?";
        }

        if (($consequence['ast_type'] ?? '') === 'execute') {
            return 'Which flow should I run, and should it run once now or on a schedule?';
        }

        if (($consequence['ast_type'] ?? '') === 'create_flow') {
            return 'What should this automation do, and who should be notified when it runs?';
        }

        if ($consequence['irreversible'] ?? false) {
            return "Before I proceed with \"{$snippet}\" — can you confirm the exact scope (what to change and what to leave untouched)?";
        }

        return "Just to be sure I help with the right thing — what would success look like for \"{$snippet}\"?";
    }

    /**
     * @param array<string, mixed> $consequence
     * @return array<string, mixed>
     */
    private function buildConfirmAssessment(string $message, array $consequence, string $automationLevel): array
    {
        $ast = ['type' => $consequence['ast_type'] ?? 'chat'];
        $actionId = (string) Str::uuid();

        return [
            'mode' => 'confirm',
            'consequence' => $consequence,
            'pending_action' => [
                'id' => $actionId,
                'message' => $message,
                'intent' => $consequence['intent'],
                'summary' => $consequence['summary'],
                'reversible' => ! ($consequence['irreversible'] ?? false),
                'automation_level' => $automationLevel,
                'tasks' => $this->buildPlanTasksFromAst($ast),
            ],
        ];
    }

    private function trustEarned(string $tenantId, string $intent): bool
    {
        if (! Schema::hasTable('tenant_business_profiles')) {
            return false;
        }

        $row = DB::table('tenant_business_profiles')->where('tenant_id', $tenantId)->first();
        if (! $row) {
            return false;
        }

        $learned = $this->decodeJson($row->learned_signals ?? null);
        $byIntent = (array) ($learned['trust']['confirmed_by_intent'] ?? []);
        $key = $intent !== '' ? $intent : 'automation';

        return (int) ($byIntent[$key] ?? 0) >= self::TRUST_CONFIRM_THRESHOLD;
    }

    private function resolveConfidence(string $message): ?float
    {
        if (! $this->inferenceConfigured()) {
            return null;
        }

        try {
            $compiled = $this->intentCompiler->compile($message);

            return (float) ($compiled['confidence'] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function inferenceConfigured(): bool
    {
        if (filter_var(env('ATLAS_INTENT_CONFIDENCE', false), FILTER_VALIDATE_BOOL)) {
            $url = (string) config('services.inference.url', env('INFERENCE_URL', ''));

            return $url !== '';
        }

        $url = (string) config('services.inference.url', env('INFERENCE_URL', ''));

        if ($url === '') {
            return false;
        }

        // Legacy dev stubs without explicit ATLAS_INTENT_CONFIDENCE
        foreach (['localhost:9000', '127.0.0.1:9000'] as $stub) {
            if (str_contains($url, $stub)) {
                return false;
            }
        }

        return true;
    }

    private function isAmbiguous(string $lower, bool $actionable, string $astType): bool
    {
        if (strlen($lower) < 4) {
            return true;
        }

        if ($this->matchesAny($lower, self::AMBIGUOUS_PATTERNS)) {
            return true;
        }

        if ($actionable && $astType === 'chat' && strlen($lower) < 18) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(string $haystack, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function summarizeAction(string $message, array $ast): string
    {
        $type = (string) ($ast['type'] ?? 'chat');

        return match ($type) {
            'create_flow' => 'Create a new automation flow: '.Str::limit(trim($message), 120),
            'execute' => 'Execute an automation: '.Str::limit(trim($message), 120),
            'query_status' => 'Check system status',
            default => Str::limit(trim($message), 140),
        };
    }

    /**
     * @param array<string, mixed> $ast
     * @return list<array<string, string>>
     */
    private function buildPlanTasksFromAst(array $ast): array
    {
        $type = $ast['type'] ?? 'chat';

        return match ($type) {
            'create_flow' => [
                ['id' => '1', 'label' => 'Parse requirements', 'status' => 'pending'],
                ['id' => '2', 'label' => 'Design automation structure', 'status' => 'pending'],
                ['id' => '3', 'label' => 'Validate and save', 'status' => 'pending'],
            ],
            'execute' => [
                ['id' => '1', 'label' => 'Load automation', 'status' => 'pending'],
                ['id' => '2', 'label' => 'Execute steps', 'status' => 'pending'],
                ['id' => '3', 'label' => 'Collect results', 'status' => 'pending'],
            ],
            'query_status' => [
                ['id' => '1', 'label' => 'Check system health', 'status' => 'pending'],
            ],
            default => [
                ['id' => '1', 'label' => 'Process your request', 'status' => 'pending'],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return json_decode($value, true) ?? [];
        }

        return [];
    }
}
