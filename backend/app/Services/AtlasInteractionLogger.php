<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AtlasInteractionLogger
 *
 * Writes one row per Atlas interaction into atlas_interactions.
 *
 * Critical contract: never block user experience. If the write fails, log
 * a warning and continue; callers must not propagate write failures to
 * response rendering.
 */
class AtlasInteractionLogger
{
    /**
     * Record the full interaction row. Returns true on success, false on failure.
     *
     * @param array{
     *   interaction_id: string,
     *   tenant_id: string,
     *   user_id?: ?string,
     *   session_id?: ?string,
     *   user_input: string,
     *   parsed_intent: array,
     *   atlas_response: array,
     *   execution_result: array,
     *   generation?: array
     * } $row
     */
    public function record(array $row): bool
    {
        try {
            DB::table('atlas_interactions')->insert([
                'id' => $row['interaction_id'],
                'tenant_id' => $row['tenant_id'],
                'user_id' => $row['user_id'] ?? null,
                'session_id' => $row['session_id'] ?? null,
                'user_input' => (string) $row['user_input'],
                'parsed_intent' => json_encode($row['parsed_intent'] ?? []),
                'atlas_response' => json_encode($row['atlas_response'] ?? []),
                'execution_result' => json_encode($row['execution_result'] ?? []),
                'generation' => json_encode($row['generation'] ?? []),
                'clicked_expand' => false,
                'accepted_action' => false,
                'follow_up' => false,
                'time_on_response_ms' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[AtlasInteractionLogger] Insert failed: '.$e->getMessage(), [
                'interaction_id' => $row['interaction_id'] ?? null,
            ]);

            return false;
        }
    }

    /**
     * Apply a behavior event update to the row (async from frontend).
     *
     * Supported event types:
     *   - RESPONSE_VIEWED
     *   - DETAILS_EXPANDED
     *   - ACTION_ACCEPTED
     *   - TIME_SPENT
     *   - FOLLOW_UP
     *   - RATING
     */
    public function applyEvent(string $tenantId, string $interactionId, string $eventType, array $data = []): bool
    {
        try {
            $update = ['updated_at' => now()];

            switch (strtoupper($eventType)) {
                case 'DETAILS_EXPANDED':
                    $update['clicked_expand'] = true;
                    break;
                case 'ACTION_ACCEPTED':
                    $update['accepted_action'] = true;
                    break;
                case 'FOLLOW_UP':
                    $update['follow_up'] = true;
                    break;
                case 'TIME_SPENT':
                    $ms = (int) ($data['duration_ms'] ?? 0);
                    if ($ms > 0) {
                        $update['time_on_response_ms'] = $ms;
                    }
                    break;
                case 'RATING':
                    $rating = (int) ($data['rating'] ?? 0);
                    if (in_array($rating, [-1, 0, 1], true)) {
                        $update['rating'] = $rating;
                    }
                    if (! empty($data['comment'])) {
                        $update['comment'] = (string) $data['comment'];
                    }
                    break;
                case 'RESPONSE_VIEWED':
                    // No-op on DB; used by analytics pipeline
                    return true;
                default:
                    // Unknown event types are silently ignored
                    return true;
            }

            $affected = DB::table('atlas_interactions')
                ->where('tenant_id', $tenantId)
                ->where('id', $interactionId)
                ->update($update);

            return $affected > 0;
        } catch (\Throwable $e) {
            Log::warning('[AtlasInteractionLogger] Event update failed: '.$e->getMessage(), [
                'interaction_id' => $interactionId,
                'event_type' => $eventType,
            ]);

            return false;
        }
    }
}
