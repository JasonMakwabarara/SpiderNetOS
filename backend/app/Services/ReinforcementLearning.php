<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class ReinforcementLearning
{
    private const EXPERIENCE_BUFFER_SIZE = 1000;
    private const LEARNING_RATE = 0.1;
    private const DISCOUNT_FACTOR = 0.95;
    private const EXPLORATION_RATE = 0.1;

    public function __construct(
        private readonly EventStore $eventStore,
        private readonly MemoryGraph $memoryGraph
    ) {}

    /**
     * Record an experience for learning
     */
    public function recordOutcome(
        string $tenantId,
        string $actionId,
        string $actionType,
        float $reward,
        array $context = [],
        array $outcome = []
    ): void {
        $experienceId = (string) \Illuminate\Support\Str::uuid();

        // Store experience in database
        DB::table('rl_experiences')->insert([
            'id' => $experienceId,
            'tenant_id' => $tenantId,
            'action_id' => $actionId,
            'action_type' => $actionType,
            'reward' => $reward,
            'context' => json_encode($context),
            'outcome' => json_encode($outcome),
            'state_vector' => json_encode($this->extractStateVector($context)),
            'timestamp' => now(),
        ]);

        // Update running statistics
        $this->updateActionStatistics($tenantId, $actionType, $actionId, $reward);

        // Store in memory graph for pattern recognition
        $this->memoryGraph->store($tenantId, [
            'type' => 'rl_experience',
            'action_type' => $actionType,
            'action_id' => $actionId,
            'reward' => $reward,
            'outcome' => $outcome,
            'context' => $context,
        ], [
            'source' => 'reinforcement_learning',
            'importance' => $this->calculateExperienceImportance($reward, $outcome),
        ]);

        // Record learning event
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'rl_learning',
            aggregateId: $experienceId,
            eventType: 'rl.experience_recorded',
            payload: [
                'action_type' => $actionType,
                'action_id' => $actionId,
                'reward' => $reward,
                'outcome_summary' => $this->summarizeOutcome($outcome),
            ]
        );

        // Trigger learning update if buffer is full
        $experienceCount = DB::table('rl_experiences')
            ->where('tenant_id', $tenantId)
            ->where('processed', false)
            ->count();

        if ($experienceCount >= self::EXPERIENCE_BUFFER_SIZE) {
            $this->updatePolicy($tenantId);
        }
    }

    /**
     * Get optimal action for given state
     */
    public function getOptimalAction(
        string $tenantId,
        array $state,
        array $possibleActions
    ): array {
        $stateVector = $this->extractStateVector($state);
        $stateKey = $this->getStateKey($stateVector);

        // Get Q-values for this state
        $qValues = $this->getQValues($tenantId, $stateKey);

        // Exploration vs exploitation
        if (rand(0, 100) / 100 < self::EXPLORATION_RATE) {
            // Explore: random action
            $optimalAction = $possibleActions[array_rand($possibleActions)];
            $selectionMethod = 'exploration';
        } else {
            // Exploit: best known action
            $bestActionId = null;
            $bestQValue = -INF;

            foreach ($possibleActions as $action) {
                $actionId = is_array($action) ? ($action['id'] ?? $action['action_id']) : $action;
                $qValue = $qValues[$actionId] ?? 0.0;

                if ($qValue > $bestQValue) {
                    $bestQValue = $qValue;
                    $bestActionId = $actionId;
                }
            }

            $optimalAction = $bestActionId ?
                (is_array($possibleActions[0]) ?
                    collect($possibleActions)->firstWhere('id', $bestActionId) ?? $possibleActions[0] :
                    $bestActionId) :
                $possibleActions[0];

            $selectionMethod = 'exploitation';
        }

        // Record action selection
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'rl_decision',
            aggregateId: (string) \Illuminate\Support\Str::uuid(),
            eventType: 'rl.action_selected',
            payload: [
                'state_key' => $stateKey,
                'selected_action' => is_array($optimalAction) ? $optimalAction['id'] ?? 'unknown' : $optimalAction,
                'selection_method' => $selectionMethod,
                'possible_actions_count' => count($possibleActions),
                'q_value' => $qValues[is_array($optimalAction) ? ($optimalAction['id'] ?? $optimalAction) : $optimalAction] ?? 0.0,
            ]
        );

        return [
            'action' => $optimalAction,
            'selection_method' => $selectionMethod,
            'confidence' => $this->calculateConfidence($qValues, $optimalAction),
            'q_value' => $qValues[is_array($optimalAction) ? ($optimalAction['id'] ?? $optimalAction) : $optimalAction] ?? 0.0,
        ];
    }

    /**
     * Predict outcome for proposed action
     */
    public function predictOutcome(
        string $tenantId,
        array $proposedAction,
        array $context
    ): array {
        $actionId = $proposedAction['id'] ?? $proposedAction['action_id'];
        $actionType = $proposedAction['type'] ?? 'unknown';

        // Get historical outcomes for similar actions
        $similarExperiences = DB::table('rl_experiences')
            ->where('tenant_id', $tenantId)
            ->where('action_type', $actionType)
            ->where('reward', '>', 0) // Only successful experiences
            ->orderBy('timestamp', 'desc')
            ->limit(50)
            ->get();

        if ($similarExperiences->isEmpty()) {
            return [
                'predicted_reward' => 0.0,
                'confidence' => 0.0,
                'sample_size' => 0,
                'similar_experiences' => 0,
            ];
        }

        // Calculate predicted reward
        $avgReward = $similarExperiences->avg('reward');
        $rewardStdDev = $this->calculateStdDev($similarExperiences->pluck('reward')->toArray());
        $sampleSize = $similarExperiences->count();

        // Adjust based on context similarity
        $contextSimilarity = $this->calculateContextSimilarity($context, $similarExperiences);
        $adjustedReward = $avgReward * (0.8 + 0.2 * $contextSimilarity);

        // Calculate confidence
        $confidence = min(1.0, $sampleSize / 10.0) * (1.0 - $rewardStdDev / max(abs($avgReward), 1.0));

        return [
            'predicted_reward' => $adjustedReward,
            'confidence' => $confidence,
            'sample_size' => $sampleSize,
            'similar_experiences' => $similarExperiences->count(),
            'reward_std_dev' => $rewardStdDev,
            'context_similarity' => $contextSimilarity,
        ];
    }

    /**
     * Update policy based on recent experiences
     */
    public function updatePolicy(string $tenantId): array
    {
        $unprocessedExperiences = DB::table('rl_experiences')
            ->where('tenant_id', $tenantId)
            ->where('processed', false)
            ->orderBy('timestamp', 'asc')
            ->get();

        if ($unprocessedExperiences->isEmpty()) {
            return ['updated' => false, 'message' => 'No unprocessed experiences'];
        }

        $updates = 0;
        $statesUpdated = [];

        foreach ($unprocessedExperiences as $experience) {
            $stateKey = $this->getStateKey(json_decode($experience->state_vector, true));
            $actionId = $experience->action_id;
            $reward = $experience->reward;

            // Get current Q-value
            $currentQ = $this->getQValue($tenantId, $stateKey, $actionId);

            // Q-learning update: Q(s,a) = Q(s,a) + α[r + γ max(Q(s',a')) - Q(s,a)]
            // Simplified for single-step updates
            $newQ = $currentQ + self::LEARNING_RATE * ($reward - $currentQ);

            // Store updated Q-value
            $this->setQValue($tenantId, $stateKey, $actionId, $newQ);

            $updates++;
            $statesUpdated[] = $stateKey;
        }

        // Mark experiences as processed
        DB::table('rl_experiences')
            ->where('tenant_id', $tenantId)
            ->where('processed', false)
            ->update(['processed' => true, 'updated_at' => now()]);

        // Discover patterns from learning data
        $patterns = $this->discoverLearningPatterns($tenantId);

        // Record policy update
        $this->eventStore->append(
            tenantId: $tenantId,
            aggregateType: 'rl_policy',
            aggregateId: (string) \Illuminate\Support\Str::uuid(),
            eventType: 'rl.policy_updated',
            payload: [
                'experiences_processed' => $updates,
                'states_updated' => count(array_unique($statesUpdated)),
                'patterns_discovered' => count($patterns),
                'avg_reward' => $unprocessedExperiences->avg('reward'),
            ]
        );

        return [
            'updated' => true,
            'experiences_processed' => $updates,
            'states_updated' => count(array_unique($statesUpdated)),
            'patterns_discovered' => count($patterns),
        ];
    }

    /**
     * Get learning statistics and insights
     */
    public function getLearningStats(string $tenantId): array
    {
        $stats = DB::select("
            SELECT
                action_type,
                COUNT(*) as experience_count,
                AVG(reward) as avg_reward,
                MAX(reward) as max_reward,
                MIN(reward) as min_reward,
                STDDEV(reward) as reward_stddev
            FROM rl_experiences
            WHERE tenant_id = ?
            GROUP BY action_type
            ORDER BY experience_count DESC
        ", [$tenantId]);

        $totalExperiences = DB::table('rl_experiences')
            ->where('tenant_id', $tenantId)
            ->count();

        $recentImprovement = DB::select("
            SELECT
                DATE(timestamp) as date,
                AVG(reward) as avg_reward,
                COUNT(*) as experience_count
            FROM rl_experiences
            WHERE tenant_id = ?
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(timestamp)
            ORDER BY date DESC
            LIMIT 7
        ", [$tenantId]);

        return [
            'total_experiences' => $totalExperiences,
            'action_type_stats' => $stats,
            'recent_performance' => $recentImprovement,
            'learning_efficiency' => $this->calculateLearningEfficiency($tenantId),
        ];
    }

    // Private helper methods

    private function extractStateVector(array $context): array
    {
        // Extract relevant features for state representation
        return [
            'time_of_day' => now()->hour,
            'day_of_week' => now()->dayOfWeek,
            'user_type' => $context['user_type'] ?? 'unknown',
            'urgency_level' => $context['urgency'] ?? 1,
            'complexity_score' => $context['complexity'] ?? 1,
            'previous_outcome' => $context['last_reward'] ?? 0,
        ];
    }

    private function getStateKey(array $stateVector): string
    {
        // Create a hash key for the state
        return hash('sha256', json_encode($stateVector));
    }

    private function getQValues(string $tenantId, string $stateKey): array
    {
        $qValues = Redis::hgetall("rl:q:{$tenantId}:{$stateKey}");
        return array_map('floatval', $qValues);
    }

    private function getQValue(string $tenantId, string $stateKey, string $actionId): float
    {
        return (float) Redis::hget("rl:q:{$tenantId}:{$stateKey}", $actionId) ?? 0.0;
    }

    private function setQValue(string $tenantId, string $stateKey, string $actionId, float $value): void
    {
        Redis::hset("rl:q:{$tenantId}:{$stateKey}", $actionId, $value);
    }

    private function updateActionStatistics(string $tenantId, string $actionType, string $actionId, float $reward): void
    {
        $key = "rl:stats:{$tenantId}:{$actionType}:{$actionId}";

        Redis::hincrbyfloat($key, 'total_reward', $reward);
        Redis::hincrby($key, 'experience_count', 1);

        if ($reward > 0) {
            Redis::hincrby($key, 'success_count', 1);
        }

        Redis::hset($key, 'last_updated', now()->timestamp);
    }

    private function calculateConfidence(array $qValues, $selectedAction): float
    {
        if (empty($qValues)) return 0.0;

        $actionId = is_array($selectedAction) ? ($selectedAction['id'] ?? $selectedAction) : $selectedAction;
        $selectedQ = $qValues[$actionId] ?? 0.0;

        $avgQ = array_sum($qValues) / count($qValues);
        $maxQ = max($qValues);

        if ($maxQ == $avgQ) return 0.5; // No clear preference

        return ($selectedQ - $avgQ) / ($maxQ - $avgQ);
    }

    private function calculateExperienceImportance(float $reward, array $outcome): float
    {
        $importance = abs($reward);

        if (isset($outcome['cost_savings']) && $outcome['cost_savings'] > 0) {
            $importance += $outcome['cost_savings'] / 100;
        }

        if (isset($outcome['user_satisfaction']) && $outcome['user_satisfaction'] > 0) {
            $importance += $outcome['user_satisfaction'];
        }

        return min($importance, 10.0);
    }

    private function summarizeOutcome(array $outcome): string
    {
        if (isset($outcome['error'])) return 'error';
        if (isset($outcome['cost_savings'])) return 'cost_saving';
        if (isset($outcome['time_saved'])) return 'time_saving';
        if (isset($outcome['user_satisfaction'])) return 'satisfaction';
        return 'general';
    }

    private function calculateStdDev(array $values): float
    {
        if (empty($values)) return 0.0;

        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / count($values);

        return sqrt($variance);
    }

    private function calculateContextSimilarity(array $context, $experiences): float
    {
        if ($experiences->isEmpty()) return 0.0;

        $similarities = [];

        foreach ($experiences as $exp) {
            $expContext = json_decode($exp->context, true);
            $similarity = $this->calculateArraySimilarity($context, $expContext);
            $similarities[] = $similarity;
        }

        return array_sum($similarities) / count($similarities);
    }

    private function calculateArraySimilarity(array $a, array $b): float
    {
        $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
        $matches = 0;

        foreach ($keys as $key) {
            if (isset($a[$key]) && isset($b[$key]) && $a[$key] == $b[$key]) {
                $matches++;
            }
        }

        return $matches / count($keys);
    }

    private function discoverLearningPatterns(string $tenantId): array
    {
        // Find patterns in successful actions
        $patterns = DB::select("
            SELECT
                action_type,
                JSON_EXTRACT(context, '$.user_type') as user_type,
                AVG(reward) as avg_reward,
                COUNT(*) as frequency
            FROM rl_experiences
            WHERE tenant_id = ?
            AND reward > 1.0
            AND timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY action_type, user_type
            HAVING frequency >= 5
            ORDER BY avg_reward DESC
        ", [$tenantId]);

        return array_map(function ($pattern) {
            return [
                'action_type' => $pattern->action_type,
                'user_type' => $pattern->user_type,
                'avg_reward' => $pattern->avg_reward,
                'frequency' => $pattern->frequency,
            ];
        }, $patterns);
    }

    private function calculateLearningEfficiency(string $tenantId): float
    {
        $recentExperiences = DB::table('rl_experiences')
            ->where('tenant_id', $tenantId)
            ->where('timestamp', '>=', now()->subDays(7))
            ->get();

        if ($recentExperiences->isEmpty()) return 0.0;

        $positiveExperiences = $recentExperiences->where('reward', '>', 0)->count();
        $totalExperiences = $recentExperiences->count();

        return $positiveExperiences / $totalExperiences;
    }
}