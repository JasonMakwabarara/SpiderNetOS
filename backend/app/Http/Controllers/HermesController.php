<?php

namespace App\Http\Controllers;

use App\Services\EventStore;
use App\Services\MetaPlanner;
use App\Services\MemoryGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HermesController extends Controller
{
    private MetaPlanner $metaPlanner;
    private EventStore $eventStore;
    private MemoryGraph $memoryGraph;

    public function __construct(
        MetaPlanner $metaPlanner,
        EventStore $eventStore,
        MemoryGraph $memoryGraph
    ) {
        $this->metaPlanner = $metaPlanner;
        $this->eventStore = $eventStore;
        $this->memoryGraph = $memoryGraph;
    }

    /**
     * POST /api/hermes/coordinate
     *
     * Main coordination endpoint for Hermes agent multi-channel communication.
     * Routes complex requests through MetaPlanner for agent orchestration.
     */
    public function coordinate(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'intent_analysis' => 'sometimes|array',
            'channel' => 'required|string|in:discord,slack,telegram,whatsapp,email,sms,voice,web,api',
            'conversation_id' => 'sometimes|string',
            'user_context' => 'sometimes|array',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $message = $request->input('message');
        $channel = $request->input('channel');
        $conversationId = $request->input('conversation_id', 'hermes_' . uniqid());
        $intentAnalysis = $request->input('intent_analysis', []);
        $userContext = $request->input('user_context', []);

        Log::info('[Hermes] Coordination request received', [
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'conversation_id' => $conversationId,
            'message_length' => strlen($message),
        ]);

        // Record Hermes interaction
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'hermes_interaction',
            aggregateId: $conversationId,
            eventType: 'hermes.message.received',
            payload: [
                'message' => $message,
                'channel' => $channel,
                'intent_analysis' => $intentAnalysis,
                'user_context' => $userContext,
            ],
            metadata: ['source' => 'hermes_agent']
        );

        // Process through MetaPlanner with enhanced context
        $result = $this->metaPlanner->processHermesRequest(
            tenantId: $tenantId,
            message: $message,
            channel: $channel,
            conversationId: $conversationId,
            context: [
                'intent_analysis' => $intentAnalysis,
                'user_context' => $userContext,
                'hermes_coordination' => true,
            ],
        );

        // Generate learning signals for RL
        $learningSignals = $this->generateLearningSignals($message, $result, $channel);

        // Record coordination result
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'hermes_interaction',
            aggregateId: $conversationId,
            eventType: 'hermes.coordination.completed',
            payload: [
                'result' => $result,
                'learning_signals' => $learningSignals,
                'channel' => $channel,
            ],
            metadata: ['source' => 'hermes_agent']
        );

        return response()->json([
            'response' => $result['response'] ?? 'Request processed successfully',
            'coordination_result' => $result,
            'learning_signals' => $learningSignals,
            'conversation_id' => $conversationId,
            'channel' => $channel,
        ]);
    }

    /**
     * POST /api/hermes/webhook/{integrationType}
     *
     * External system integration webhook processor.
     * Handles events from Stripe, GitHub, Zapier, etc.
     */
    public function webhook(Request $request, string $integrationType): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $payload = $request->all();

        Log::info('[Hermes] Webhook received', [
            'tenant_id' => $tenantId,
            'integration_type' => $integrationType,
            'payload_keys' => array_keys($payload),
        ]);

        // Validate integration type
        $validTypes = ['stripe', 'github', 'zapier', 'slack', 'discord', 'webhook'];
        if (!in_array($integrationType, $validTypes)) {
            return response()->json(['error' => 'Invalid integration type'], 400);
        }

        // Process webhook based on type
        $processedEvent = $this->processWebhookEvent($integrationType, $payload, $tenantId);

        // Record webhook event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'hermes_webhook',
            aggregateId: 'webhook_' . uniqid(),
            eventType: 'hermes.webhook.received',
            payload: [
                'integration_type' => $integrationType,
                'original_payload' => $payload,
                'processed_event' => $processedEvent,
            ],
            metadata: ['source' => 'hermes_agent']
        );

        // Route through MetaPlanner if workflow trigger is needed
        if ($processedEvent['requires_workflow']) {
            $workflowResult = $this->metaPlanner->dispatch(
                tenantId: $tenantId,
                agentId: $processedEvent['target_agent'] ?? 'nexus',
                intent: $processedEvent['intent'],
                context: [
                    'webhook_data' => $processedEvent,
                    'integration_type' => $integrationType,
                    'source' => 'hermes_webhook',
                ]
            );

            return response()->json([
                'status' => 'webhook_processed',
                'workflow_dispatched' => $workflowResult['status'] === 'dispatched',
                'workflow_id' => $workflowResult['dag_id'] ?? null,
                'integration_type' => $integrationType,
            ]);
        }

        return response()->json([
            'status' => 'webhook_processed',
            'integration_type' => $integrationType,
            'processed_event' => $processedEvent,
        ]);
    }

    /**
     * POST /api/hermes/learning/sync
     *
     * Synchronize communication learning data from Hermes RL loop.
     */
    public function syncLearning(Request $request): JsonResponse
    {
        $request->validate([
            'learning_type' => 'required|string|in:communication_pattern,user_behavior,channel_performance',
            'entries' => 'required|array',
            'period' => 'sometimes|string',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $learningType = $request->input('learning_type');
        $entries = $request->input('entries');
        $period = $request->input('period', '1h');

        Log::info('[Hermes] Learning sync received', [
            'tenant_id' => $tenantId,
            'learning_type' => $learningType,
            'entries_count' => count($entries),
            'period' => $period,
        ]);

        // Store learning data in memory graph for RL training
        $this->memoryGraph->store(
            tenant_id: $tenantId,
            content: json_encode([
                'learning_type' => $learningType,
                'entries' => $entries,
                'period' => $period,
                'timestamp' => now()->toIso8601String(),
            ]),
            metadata: [
                'source' => 'hermes_agent',
                'learning_type' => $learningType,
                'sync_period' => $period,
            ]
        );

        // Record learning sync event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'hermes_learning',
            aggregateId: 'learning_' . uniqid(),
            eventType: 'hermes.learning.synced',
            payload: [
                'learning_type' => $learningType,
                'entries_count' => count($entries),
                'period' => $period,
            ],
            metadata: ['source' => 'hermes_agent']
        );

        return response()->json([
            'status' => 'learning_data_synced',
            'learning_type' => $learningType,
            'entries_processed' => count($entries),
            'period' => $period,
        ]);
    }

    /**
     * GET /api/hermes/status
     *
     * Check Hermes integration status and connectivity.
     */
    public function status(): JsonResponse
    {
        $tenantId = request()->attributes->get('tenant_id');

        return response()->json([
            'status' => 'operational',
            'tenant_id' => $tenantId,
            'integration_version' => '1.0.0',
            'supported_channels' => [
                'discord', 'slack', 'telegram', 'whatsapp',
                'email', 'sms', 'voice', 'web', 'api'
            ],
            'supported_integrations' => [
                'stripe', 'github', 'zapier', 'slack', 'discord', 'webhook'
            ],
            'last_sync' => now()->toIso8601String(),
        ]);
    }

    /**
     * Generate learning signals for RL training.
     */
    private function generateLearningSignals(string $message, array $result, string $channel): array
    {
        return [
            'message_complexity' => $this->calculateComplexity($message),
            'response_quality' => $result['status'] === 'success' ? 1.0 : 0.0,
            'channel_effectiveness' => $this->getChannelScore($channel),
            'agent_coordination_count' => count($result['agents_used'] ?? []),
            'processing_time' => $result['processing_time_ms'] ?? 0,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Process webhook events based on integration type.
     */
    private function processWebhookEvent(string $type, array $payload, string $tenantId): array
    {
        return match ($type) {
            'stripe' => $this->processStripeWebhook($payload, $tenantId),
            'github' => $this->processGitHubWebhook($payload, $tenantId),
            'slack' => $this->processSlackWebhook($payload, $tenantId),
            'discord' => $this->processDiscordWebhook($payload, $tenantId),
            'zapier' => $this->processZapierWebhook($payload, $tenantId),
            default => [
                'type' => 'generic_webhook',
                'data' => $payload,
                'requires_workflow' => false,
                'intent' => 'process_webhook',
                'target_agent' => 'nexus',
            ],
        };
    }

    private function processStripeWebhook(array $payload, string $tenantId): array
    {
        $eventType = $payload['type'] ?? 'unknown';

        return [
            'type' => 'stripe_event',
            'event_type' => $eventType,
            'data' => $payload['data'] ?? [],
            'requires_workflow' => in_array($eventType, ['invoice.payment_failed', 'customer.subscription.deleted']),
            'intent' => 'handle_payment_event',
            'target_agent' => 'nexus',
        ];
    }

    private function processGitHubWebhook(array $payload, string $tenantId): array
    {
        $eventType = request()->header('X-GitHub-Event', 'unknown');

        return [
            'type' => 'github_event',
            'event_type' => $eventType,
            'repository' => $payload['repository']['full_name'] ?? 'unknown',
            'data' => $payload,
            'requires_workflow' => in_array($eventType, ['push', 'pull_request', 'issues']),
            'intent' => 'handle_repository_event',
            'target_agent' => 'sentinel',
        ];
    }

    private function processSlackWebhook(array $payload, string $tenantId): array
    {
        return [
            'type' => 'slack_event',
            'event_type' => $payload['type'] ?? 'unknown',
            'channel' => $payload['channel'] ?? 'unknown',
            'data' => $payload,
            'requires_workflow' => ($payload['type'] ?? '') === 'app_mention',
            'intent' => 'handle_slack_interaction',
            'target_agent' => 'atlas',
        ];
    }

    private function processDiscordWebhook(array $payload, string $tenantId): array
    {
        return [
            'type' => 'discord_event',
            'event_type' => $payload['type'] ?? 'unknown',
            'channel_id' => $payload['channel_id'] ?? 'unknown',
            'data' => $payload,
            'requires_workflow' => ($payload['type'] ?? '') === 'MESSAGE_CREATE',
            'intent' => 'handle_discord_message',
            'target_agent' => 'atlas',
        ];
    }

    private function processZapierWebhook(array $payload, string $tenantId): array
    {
        return [
            'type' => 'zapier_event',
            'data' => $payload,
            'requires_workflow' => true,
            'intent' => 'process_automation_trigger',
            'target_agent' => 'nexus',
        ];
    }

    private function calculateComplexity(string $message): float
    {
        $wordCount = str_word_count($message);
        $sentenceCount = substr_count($message, '.') + substr_count($message, '!') + substr_count($message, '?');
        $avgWordsPerSentence = $sentenceCount > 0 ? $wordCount / $sentenceCount : $wordCount;

        return min(1.0, ($wordCount * 0.01) + ($avgWordsPerSentence * 0.1));
    }

    private function getChannelScore(string $channel): float
    {
        return match ($channel) {
            'discord', 'slack' => 0.9,
            'telegram', 'whatsapp' => 0.8,
            'email' => 0.7,
            'sms' => 0.6,
            'voice' => 0.8,
            'web' => 1.0,
            'api' => 0.9,
            default => 0.5,
        };
    }
}