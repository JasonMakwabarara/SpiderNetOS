<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class AgentMesh
{
    private const CAPABILITY_TTL = 3600; // 1 hour
    private const MESSAGE_TTL = 300; // 5 minutes
    private const NEGOTIATION_TIMEOUT = 30; // 30 seconds

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly MetaPlanner $metaPlanner
    ) {}

    /**
     * Register an agent and its capabilities in the mesh
     */
    public function registerAgent(
        string $tenantId,
        string $agentId,
        array $capabilities,
        array $metadata = []
    ): void {
        $registrationKey = "agent_mesh:registration:{$tenantId}:{$agentId}";
        $capabilityKey = "agent_mesh:capabilities:{$tenantId}:{$agentId}";

        // Store agent registration
        Redis::hmset($registrationKey, [
            'agent_id' => $agentId,
            'tenant_id' => $tenantId,
            'capabilities' => json_encode($capabilities),
            'metadata' => json_encode($metadata),
            'registered_at' => now()->timestamp,
            'last_heartbeat' => now()->timestamp,
            'status' => 'active',
        ]);

        Redis::expire($registrationKey, self::CAPABILITY_TTL);

        // Index capabilities for discovery
        foreach ($capabilities as $capability) {
            $capKey = "agent_mesh:capability_index:{$tenantId}:{$capability}";
            Redis::sadd($capKey, $agentId);
            Redis::expire($capKey, self::CAPABILITY_TTL);
        }

        // Store in database for persistence
        DB::table('agent_mesh_registrations')->updateOrInsert(
            ['tenant_id' => $tenantId, 'agent_id' => $agentId],
            [
                'capabilities' => json_encode($capabilities),
                'metadata' => json_encode($metadata),
                'last_seen_at' => now(),
                'is_active' => true,
                'updated_at' => now(),
            ]
        );

        // Record registration event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent_mesh',
            aggregateId: $agentId,
            eventType: 'agent_mesh.agent_registered',
            payload: [
                'capabilities_count' => count($capabilities),
                'metadata_keys' => array_keys($metadata),
            ]
        );

        Log::info("Agent registered in mesh", [
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'capabilities' => $capabilities
        ]);
    }

    /**
     * Discover agents with specific capabilities
     */
    public function discoverAgents(
        string $tenantId,
        array $requiredCapabilities,
        array $filters = []
    ): array {
        $matchingAgents = [];

        // Find agents with ALL required capabilities
        $candidateAgents = null;

        foreach ($requiredCapabilities as $capability) {
            $capKey = "agent_mesh:capability_index:{$tenantId}:{$capability}";
            $agentsWithCap = Redis::smembers($capKey);

            if ($candidateAgents === null) {
                $candidateAgents = $agentsWithCap;
            } else {
                $candidateAgents = array_intersect($candidateAgents, $agentsWithCap);
            }

            if (empty($candidateAgents)) {
                break; // No agents have all required capabilities
            }
        }

        if (empty($candidateAgents)) {
            return [];
        }

        // Get detailed information for matching agents
        foreach ($candidateAgents as $agentId) {
            $regKey = "agent_mesh:registration:{$tenantId}:{$agentId}";
            $agentData = Redis::hgetall($regKey);

            if (empty($agentData)) {
                continue; // Agent not found or expired
            }

            // Apply filters
            if (!$this->matchesFilters($agentData, $filters)) {
                continue;
            }

            $matchingAgents[] = [
                'agent_id' => $agentId,
                'capabilities' => json_decode($agentData['capabilities'] ?? '[]', true),
                'metadata' => json_decode($agentData['metadata'] ?? '{}', true),
                'last_heartbeat' => (int) ($agentData['last_heartbeat'] ?? 0),
                'status' => $agentData['status'] ?? 'unknown',
                'capability_match_score' => $this->calculateCapabilityMatch(
                    $requiredCapabilities,
                    json_decode($agentData['capabilities'] ?? '[]', true)
                ),
            ];
        }

        // Sort by capability match score (descending)
        usort($matchingAgents, fn($a, $b) => $b['capability_match_score'] <=> $a['capability_match_score']);

        // Record discovery event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent_mesh',
            aggregateId: (string) \Illuminate\Support\Str::uuid(),
            eventType: 'agent_mesh.agents_discovered',
            payload: [
                'required_capabilities' => $requiredCapabilities,
                'agents_found' => count($matchingAgents),
                'filters_applied' => array_keys($filters),
            ]
        );

        return $matchingAgents;
    }

    /**
     * Send message to specific agent
     */
    public function sendMessage(
        string $tenantId,
        string $fromAgentId,
        string $toAgentId,
        array $message
    ): bool {
        $messageId = (string) \Illuminate\Support\Str::uuid();
        $messageKey = "agent_mesh:message:{$tenantId}:{$toAgentId}:{$messageId}";

        $messageData = [
            'message_id' => $messageId,
            'from_agent' => $fromAgentId,
            'to_agent' => $toAgentId,
            'message_type' => $message['type'] ?? 'direct',
            'payload' => json_encode($message['payload'] ?? []),
            'correlation_id' => $message['correlation_id'] ?? null,
            'priority' => $message['priority'] ?? 'normal',
            'sent_at' => now()->timestamp,
            'expires_at' => now()->addSeconds(self::MESSAGE_TTL)->timestamp,
        ];

        // Store message in Redis for immediate delivery
        Redis::hmset($messageKey, $messageData);
        Redis::expire($messageKey, self::MESSAGE_TTL);

        // Add to agent's message queue
        $queueKey = "agent_mesh:queue:{$tenantId}:{$toAgentId}";
        Redis::rpush($queueKey, $messageId);

        // Publish to Redis pub/sub for real-time delivery
        Redis::publish("agent_mesh:{$tenantId}:{$toAgentId}", json_encode($messageData));

        // Record message event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent_mesh',
            aggregateId: $messageId,
            eventType: 'agent_mesh.message_sent',
            payload: [
                'from_agent' => $fromAgentId,
                'to_agent' => $toAgentId,
                'message_type' => $messageData['message_type'],
                'priority' => $messageData['priority'],
            ]
        );

        Log::info("Agent mesh message sent", [
            'tenant_id' => $tenantId,
            'from_agent' => $fromAgentId,
            'to_agent' => $toAgentId,
            'message_id' => $messageId,
        ]);

        return true;
    }

    /**
     * Broadcast message to all agents with specific capability
     */
    public function broadcastMessage(
        string $tenantId,
        string $fromAgentId,
        array $message,
        string $capabilityFilter = null
    ): array {
        $targetAgents = [];

        if ($capabilityFilter) {
            // Find agents with specific capability
            $capKey = "agent_mesh:capability_index:{$tenantId}:{$capabilityFilter}";
            $targetAgents = Redis::smembers($capKey);
        } else {
            // Broadcast to all registered agents
            $registrationKeys = Redis::keys("agent_mesh:registration:{$tenantId}:*");
            $targetAgents = array_map(function ($key) {
                return str_replace("agent_mesh:registration:{$tenantId}:", "", $key);
            }, $registrationKeys);
        }

        $results = [];
        foreach ($targetAgents as $agentId) {
            if ($agentId !== $fromAgentId) { // Don't send to self
                $success = $this->sendMessage($tenantId, $fromAgentId, $agentId, $message);
                $results[] = [
                    'agent_id' => $agentId,
                    'delivered' => $success,
                ];
            }
        }

        // Record broadcast event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent_mesh',
            aggregateId: (string) \Illuminate\Support\Str::uuid(),
            eventType: 'agent_mesh.message_broadcast',
            payload: [
                'from_agent' => $fromAgentId,
                'capability_filter' => $capabilityFilter,
                'recipients_count' => count($results),
                'message_type' => $message['type'] ?? 'broadcast',
            ]
        );

        return $results;
    }

    /**
     * Request collaboration from multiple agents
     */
    public function requestCollaboration(
        string $tenantId,
        string $initiatorAgentId,
        string $collaborationType,
        array $requirements,
        array $participants = null
    ): array {
        $collaborationId = (string) \Illuminate\Support\Str::uuid();

        if ($participants === null) {
            // Auto-discover participants based on requirements
            $participants = $this->discoverAgents($tenantId, $requirements['capabilities'] ?? []);
            $participants = array_column($participants, 'agent_id');
        }

        // Create collaboration session
        $sessionKey = "agent_mesh:collaboration:{$tenantId}:{$collaborationId}";
        Redis::hmset($sessionKey, [
            'collaboration_id' => $collaborationId,
            'initiator' => $initiatorAgentId,
            'type' => $collaborationType,
            'requirements' => json_encode($requirements),
            'participants' => json_encode($participants),
            'status' => 'negotiating',
            'created_at' => now()->timestamp,
            'expires_at' => now()->addSeconds(self::NEGOTIATION_TIMEOUT)->timestamp,
        ]);

        Redis::expire($sessionKey, self::NEGOTIATION_TIMEOUT);

        // Send collaboration requests to participants
        $responses = [];
        foreach ($participants as $participantId) {
            $response = $this->sendMessage($tenantId, $initiatorAgentId, $participantId, [
                'type' => 'collaboration_request',
                'collaboration_id' => $collaborationId,
                'collaboration_type' => $collaborationType,
                'requirements' => $requirements,
                'response_deadline' => now()->addSeconds(self::NEGOTIATION_TIMEOUT)->timestamp,
            ]);

            $responses[] = [
                'participant_id' => $participantId,
                'request_sent' => $response,
            ];
        }

        // Record collaboration event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent_mesh',
            aggregateId: $collaborationId,
            eventType: 'agent_mesh.collaboration_requested',
            payload: [
                'initiator' => $initiatorAgentId,
                'collaboration_type' => $collaborationType,
                'participants_count' => count($participants),
                'requirements_keys' => array_keys($requirements),
            ]
        );

        return [
            'collaboration_id' => $collaborationId,
            'participants' => $participants,
            'status' => 'negotiating',
            'responses' => $responses,
        ];
    }

    /**
     * Respond to collaboration request
     */
    public function respondToCollaboration(
        string $tenantId,
        string $agentId,
        string $collaborationId,
        bool $accepted,
        array $terms = []
    ): bool {
        $sessionKey = "agent_mesh:collaboration:{$tenantId}:{$collaborationId}";
        $sessionData = Redis::hgetall($sessionKey);

        if (empty($sessionData)) {
            Log::warning("Collaboration session not found", [
                'tenant_id' => $tenantId,
                'collaboration_id' => $collaborationId,
                'agent_id' => $agentId,
            ]);
            return false;
        }

        $participants = json_decode($sessionData['participants'] ?? '[]', true);
        if (!in_array($agentId, $participants)) {
            Log::warning("Agent not participant in collaboration", [
                'tenant_id' => $tenantId,
                'collaboration_id' => $collaborationId,
                'agent_id' => $agentId,
            ]);
            return false;
        }

        // Record response
        $responseKey = "agent_mesh:collaboration_response:{$tenantId}:{$collaborationId}:{$agentId}";
        Redis::hmset($responseKey, [
            'agent_id' => $agentId,
            'accepted' => $accepted ? '1' : '0',
            'terms' => json_encode($terms),
            'responded_at' => now()->timestamp,
        ]);

        // Notify initiator
        $this->sendMessage($tenantId, $agentId, $sessionData['initiator'], [
            'type' => 'collaboration_response',
            'collaboration_id' => $collaborationId,
            'accepted' => $accepted,
            'terms' => $terms,
        ]);

        // Check if all participants have responded
        $this->checkCollaborationCompletion($tenantId, $collaborationId);

        // Record response event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'agent_mesh',
            aggregateId: $collaborationId,
            eventType: 'agent_mesh.collaboration_response',
            payload: [
                'agent_id' => $agentId,
                'accepted' => $accepted,
                'terms_count' => count($terms),
            ]
        );

        return true;
    }

    /**
     * Get agent's message queue
     */
    public function getMessageQueue(string $tenantId, string $agentId, int $limit = 10): array
    {
        $queueKey = "agent_mesh:queue:{$tenantId}:{$agentId}";
        $messageIds = Redis::lrange($queueKey, 0, $limit - 1);

        $messages = [];
        foreach ($messageIds as $messageId) {
            $messageKey = "agent_mesh:message:{$tenantId}:{$agentId}:{$messageId}";
            $messageData = Redis::hgetall($messageKey);

            if (!empty($messageData)) {
                $messages[] = [
                    'message_id' => $messageId,
                    'from_agent' => $messageData['from_agent'] ?? 'unknown',
                    'message_type' => $messageData['message_type'] ?? 'unknown',
                    'payload' => json_decode($messageData['payload'] ?? '{}', true),
                    'priority' => $messageData['priority'] ?? 'normal',
                    'sent_at' => (int) ($messageData['sent_at'] ?? 0),
                ];
            }
        }

        return $messages;
    }

    /**
     * Get mesh health and statistics
     */
    public function getMeshStats(string $tenantId): array
    {
        $registrationKeys = Redis::keys("agent_mesh:registration:{$tenantId}:*");
        $activeAgents = count($registrationKeys);

        $capabilityKeys = Redis::keys("agent_mesh:capability_index:{$tenantId}:*");
        $uniqueCapabilities = count($capabilityKeys);

        $queueKeys = Redis::keys("agent_mesh:queue:{$tenantId}:*");
        $totalQueuedMessages = 0;
        foreach ($queueKeys as $queueKey) {
            $totalQueuedMessages += Redis::llen($queueKey);
        }

        $collaborationKeys = Redis::keys("agent_mesh:collaboration:{$tenantId}:*");
        $activeCollaborations = count($collaborationKeys);

        return [
            'active_agents' => $activeAgents,
            'unique_capabilities' => $uniqueCapabilities,
            'queued_messages' => $totalQueuedMessages,
            'active_collaborations' => $activeCollaborations,
            'average_messages_per_agent' => $activeAgents > 0 ? $totalQueuedMessages / $activeAgents : 0,
        ];
    }

    // Private helper methods

    private function matchesFilters(array $agentData, array $filters): bool
    {
        foreach ($filters as $key => $value) {
            $agentValue = $agentData[$key] ?? null;

            if ($agentValue === null) {
                continue; // Filter key not present in agent data
            }

            // Simple equality check (can be extended for more complex filtering)
            if ($agentValue != $value) {
                return false;
            }
        }

        return true;
    }

    private function calculateCapabilityMatch(array $required, array $available): float
    {
        if (empty($required)) return 1.0;

        $matches = 0;
        foreach ($required as $reqCap) {
            if (in_array($reqCap, $available)) {
                $matches++;
            }
        }

        return $matches / count($required);
    }

    private function checkCollaborationCompletion(string $tenantId, string $collaborationId): void
    {
        $sessionKey = "agent_mesh:collaboration:{$tenantId}:{$collaborationId}";
        $sessionData = Redis::hgetall($sessionKey);

        if (empty($sessionData)) return;

        $participants = json_decode($sessionData['participants'] ?? '[]', true);
        $responses = [];

        foreach ($participants as $participantId) {
            $responseKey = "agent_mesh:collaboration_response:{$tenantId}:{$collaborationId}:{$participantId}";
            $response = Redis::hgetall($responseKey);
            if (!empty($response)) {
                $responses[] = $response;
            }
        }

        if (count($responses) === count($participants)) {
            // All participants have responded
            $acceptedCount = count(array_filter($responses, fn($r) => ($r['accepted'] ?? '0') === '1'));

            $finalStatus = $acceptedCount === count($participants) ? 'accepted' :
                          ($acceptedCount > 0 ? 'partial' : 'rejected');

            Redis::hset($sessionKey, 'status', $finalStatus);
            Redis::hset($sessionKey, 'completed_at', now()->timestamp);

            // Notify all participants of final result
            foreach ($participants as $participantId) {
                $this->sendMessage($tenantId, 'mesh_coordinator', $participantId, [
                    'type' => 'collaboration_finalized',
                    'collaboration_id' => $collaborationId,
                    'status' => $finalStatus,
                    'accepted_count' => $acceptedCount,
                    'total_participants' => count($participants),
                ]);
            }

            // Record completion event
            $this->eventStore->append(
                tenantId: $tenantId,
                aggregateType: 'agent_mesh',
                aggregateId: $collaborationId,
                eventType: 'agent_mesh.collaboration_completed',
                payload: [
                    'final_status' => $finalStatus,
                    'accepted_count' => $acceptedCount,
                    'total_participants' => count($participants),
                    'completion_time_seconds' => now()->timestamp - ($sessionData['created_at'] ?? 0),
                ]
            );
        }
    }
}