<?php

namespace App\Http\Controllers;

use App\Services\EventStore;
use App\Services\MetaPlanner;
use App\Services\PromptEnhancer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CommandController extends Controller
{
    private EventStore $eventStore;

    private MetaPlanner $metaPlanner;

    private PromptEnhancer $promptEnhancer;

    public function __construct(EventStore $eventStore, MetaPlanner $metaPlanner, PromptEnhancer $promptEnhancer)
    {
        $this->eventStore = $eventStore;
        $this->metaPlanner = $metaPlanner;
        $this->promptEnhancer = $promptEnhancer;
    }

    /**
     * Execute a command through the full pipeline:
     * command.received -> AST parse -> MetaPlanner dispatch -> agent execution -> result
     */
    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'command' => 'required|string|max:2000',
            'session_id' => 'nullable|string|uuid',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->user()->id;
        $commandText = $request->input('command');
        $sessionId = $request->input('session_id', (string) Str::uuid());

        // Record command.received event (Hard Rule #1)
        $commandId = (string) Str::uuid();
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'command',
            aggregateId: $commandId,
            eventType: 'command.received',
            payload: [
                'text' => $commandText,
                'user_id' => $userId,
                'session_id' => $sessionId,
            ],
            metadata: [
                'ip' => $request->ip(),
            ]
        );

        // Dispatch through MetaPlanner (Hard Rule #2)
        $result = $this->metaPlanner->processAtlasRequest(
            tenantId: $tenantId,
            userId: $userId,
            message: $commandText,
            sessionId: $sessionId,
        );

        // Record completion event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'command',
            aggregateId: $commandId,
            eventType: $result['status'] === 'dispatched' ? 'command.dispatched' : 'command.failed',
            payload: [
                'result' => $result,
                'session_id' => $sessionId,
            ],
        );

        return response()->json([
            'command_id' => $commandId,
            'session_id' => $sessionId,
            'status' => $result['status'],
            'event_id' => $result['event_id'] ?? null,
            'dag_id' => $result['dag_id'] ?? null,
            'cost_status' => $result['cost_status'] ?? null,
        ]);
    }

    /**
     * Enhance a raw operator/user prompt before execution.
     */
    public function enhancePrompt(Request $request): JsonResponse
    {
        $request->validate([
            'prompt' => 'required|string|max:8000',
            'mode' => 'nullable|string|in:concise,balanced,deep',
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $userId = $request->user()->id;
        $prompt = $request->input('prompt');
        $mode = $request->input('mode', 'balanced');

        $enhanced = $this->promptEnhancer->enhance($prompt, ['mode' => $mode]);

        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'command',
            aggregateId: (string) Str::uuid(),
            eventType: 'command.prompt_enhanced',
            payload: [
                'mode' => $mode,
                'original_length' => mb_strlen($prompt),
                'enhanced_length' => mb_strlen($enhanced['enhanced'] ?? ''),
            ],
            metadata: [
                'user_id' => $userId,
            ]
        );

        return response()->json($enhanced);
    }
}
