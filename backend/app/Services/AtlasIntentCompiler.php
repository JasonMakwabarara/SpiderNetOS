<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * AtlasIntentCompiler - Hybrid Intent Parser for SpiderNet OS v3.2
 *
 * Pattern matches FIRST (free, no tokens) for known slash commands,
 * then falls back to LLM inference for ambiguous/complex natural language.
 */
class AtlasIntentCompiler
{
    /**
     * Mapping of intents to their designated agent targets.
     */
    private const INTENT_AGENT_MAP = [
        'create_flow'   => 'forge',
        'execute_flow'  => 'nexus',
        'query_status'  => 'sentinel',
        'analyze_data'  => 'prism',
        'teach'         => 'hannah',
        'monitor'       => 'sentinel',
        'manage_agent'  => 'nexus',
        'chat'          => 'atlas',
    ];

    /**
     * Slash command patterns mapped to intents.
     * Order matters: more specific patterns should come first.
     */
    private const COMMAND_PATTERNS = [
        '/^\/research\s+(.+)/i' => [
            'intent'     => 'analyze_data',
            'entity_key' => 'research_query',
        ],
        // /create flow ... → create_flow
        '/^\/create\s+flow\s+(.+)/i' => [
            'intent'     => 'create_flow',
            'entity_key' => 'flow_definition',
        ],
        // /run ... or /execute ... → execute_flow
        '/^\/(?:run|execute)\s+(.+)/i' => [
            'intent'     => 'execute_flow',
            'entity_key' => 'flow_target',
        ],
        // /analyze ... → analyze_data
        '/^\/analyze\s+(.+)/i' => [
            'intent'     => 'analyze_data',
            'entity_key' => 'analysis_target',
        ],
        // /help or /explain ... → teach
        '/^\/help(?:\s+(.*))?$/i' => [
            'intent'     => 'teach',
            'entity_key' => 'topic',
        ],
        '/^\/explain\s+(.+)/i' => [
            'intent'     => 'teach',
            'entity_key' => 'topic',
        ],
        // /status or /health → query_status
        '/^\/(?:status|health)(?:\s+(.*))?$/i' => [
            'intent'     => 'query_status',
            'entity_key' => 'target',
        ],
        // /monitor → monitor
        '/^\/monitor(?:\s+(.*))?$/i' => [
            'intent'     => 'monitor',
            'entity_key' => 'target',
        ],
        // /agents → manage_agent
        '/^\/agents(?:\s+(.*))?$/i' => [
            'intent'     => 'manage_agent',
            'entity_key' => 'action',
        ],
    ];

    /**
     * Intents that typically require multi-step planning.
     */
    private const PLANNING_INTENTS = [
        'create_flow',
        'execute_flow',
        'analyze_data',
    ];

    /**
     * Compile a user message into a structured intent result.
     *
     * @param string $message Raw user input
     * @return array{
     *     intent: string,
     *     entities: array,
     *     confidence: float,
     *     agent_target: string|null,
     *     requires_planning: bool,
     *     raw_input: string
     * }
     */
    public function compile(string $message): array
    {
        $message = trim($message);

        if (empty($message)) {
            return $this->buildResult('chat', [], 1.0, $message);
        }

        // Phase 1: Pattern matching (free, no tokens)
        $patternResult = $this->matchPattern($message);
        if ($patternResult !== null) {
            Log::debug('AtlasIntentCompiler: Pattern match hit', [
                'intent'  => $patternResult['intent'],
                'message' => $message,
            ]);
            return $patternResult;
        }

        // Phase 2: LLM fallback for ambiguous/complex natural language
        return $this->classifyWithLLM($message);
    }

    /**
     * Attempt to match the message against known slash command patterns.
     *
     * @param string $message
     * @return array|null Structured result or null if no pattern matched
     */
    private function matchPattern(string $message): ?array
    {
        foreach (self::COMMAND_PATTERNS as $pattern => $config) {
            if (preg_match($pattern, $message, $matches)) {
                $entities = [];

                // Extract the captured entity value if present
                $capturedValue = isset($matches[1]) ? trim($matches[1]) : '';
                if (!empty($capturedValue) && !empty($config['entity_key'])) {
                    $entities[$config['entity_key']] = $capturedValue;
                }

                return $this->buildResult(
                    $config['intent'],
                    $entities,
                    1.0, // Pattern matches are 100% confident
                    $message
                );
            }
        }

        return null;
    }

    /**
     * Classify intent using the inference plane LLM endpoint.
     *
     * @param string $message
     * @return array Structured intent result
     */
    private function classifyWithLLM(string $message): array
    {
        $inferenceUrl = config('services.inference.url');

        if (empty($inferenceUrl)) {
            Log::warning('AtlasIntentCompiler: No inference URL configured, defaulting to chat intent');
            return $this->buildResult('chat', [], 0.5, $message);
        }

        try {
            $model = (string) config('services.spidernet.prompt_enhancer_model', 'gemma2:2b');
            $response = Http::timeout(60)
                ->retry(2, 500)
                ->post(rtrim($inferenceUrl, '/') . '/v1/classify', [
                    'message' => $message,
                    'model' => $model,
                    'schema'  => [
                        'type'       => 'object',
                        'properties' => [
                            'intent' => [
                                'type' => 'string',
                                'enum' => array_keys(self::INTENT_AGENT_MAP),
                            ],
                            'entities' => [
                                'type' => 'object',
                            ],
                            'confidence' => [
                                'type'    => 'number',
                                'minimum' => 0.0,
                                'maximum' => 1.0,
                            ],
                        ],
                        'required' => ['intent', 'confidence'],
                    ],
                    'system_prompt' => $this->buildClassificationPrompt(),
                ]);

            if ($response->successful()) {
                $data = $response->json();

                $intent     = $data['intent'] ?? 'chat';
                $entities   = $data['entities'] ?? [];
                $confidence = (float) ($data['confidence'] ?? 0.5);

                // Validate the intent is known
                if (!array_key_exists($intent, self::INTENT_AGENT_MAP)) {
                    Log::warning('AtlasIntentCompiler: LLM returned unknown intent', [
                        'intent'  => $intent,
                        'message' => $message,
                    ]);
                    $intent     = 'chat';
                    $confidence = 0.3;
                }

                Log::debug('AtlasIntentCompiler: LLM classification', [
                    'intent'     => $intent,
                    'confidence' => $confidence,
                    'message'    => $message,
                ]);

                return $this->buildResult($intent, $entities, $confidence, $message);
            }

            Log::error('AtlasIntentCompiler: Inference endpoint returned error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AtlasIntentCompiler: LLM classification failed', [
                'error'   => $e->getMessage(),
                'message' => $message,
            ]);
        }

        // Fallback: treat as general chat
        return $this->buildResult('chat', [], 0.3, $message);
    }

    /**
     * Build the classification system prompt for the LLM.
     */
    private function buildClassificationPrompt(): string
    {
        return <<<PROMPT
You are an intent classifier for SpiderNet OS. Classify the user's message into exactly one intent.

Available intents:
- create_flow: User wants to create, build, or design a new automation flow or pipeline.
- execute_flow: User wants to run, execute, or trigger an existing flow.
- query_status: User is asking about system status, health, metrics, or state of components.
- analyze_data: User wants to analyze, inspect, or get insights from data.
- teach: User is asking for help, explanations, tutorials, or wants to learn something.
- monitor: User wants to monitor, watch, or observe system activity in real-time.
- manage_agent: User wants to list, configure, enable, disable, or manage agents.
- chat: General conversation that doesn't fit the above categories.

Respond with a JSON object containing:
- intent: one of the above intent strings
- entities: relevant extracted entities as key-value pairs
- confidence: your confidence level from 0.0 to 1.0
PROMPT;
    }

    /**
     * Build the standardized result array.
     *
     * @param string $intent
     * @param array  $entities
     * @param float  $confidence
     * @param string $rawInput
     * @return array
     */
    private function buildResult(string $intent, array $entities, float $confidence, string $rawInput): array
    {
        return [
            'intent'            => $intent,
            'entities'          => $entities,
            'confidence'        => round($confidence, 4),
            'agent_target'      => self::INTENT_AGENT_MAP[$intent] ?? null,
            'requires_planning' => in_array($intent, self::PLANNING_INTENTS, true),
            'raw_input'         => $rawInput,
        ];
    }
}
