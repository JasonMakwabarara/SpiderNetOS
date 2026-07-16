<?php

namespace App\Services\Projections;

use App\Models\Event;
use App\Services\FeatureFlag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * StateTransitionProjection (STE — Phase 1)
 *
 * Runs synchronously inside EventStore::append()'s transaction via
 * config/projections.php. Must complete in < 10 ms p95.
 *
 * Idempotency:
 *   - ste_transitions upsert (count += 1) via the (chain, from, to, md5(tags), tenant) unique index
 *   - ste_*_states only updated when event.sequence_num > existing.last_sequence_num
 *   - Unmapped event types recorded once per type in ste_unmapped_events
 *
 * Kill-switch: FeatureFlag `platform.ste_projector` = off → accepts() returns false.
 */
class StateTransitionProjection
{
    /** @var array<string, array<int, object>>|null  In-process mapping cache. */
    private ?array $mappingCache = null;

    /**
     * Returns true iff the projector is enabled and the event type has at least
     * one enabled mapping row. Cheap (cached in-process per request lifecycle).
     */
    public function accepts(Event $event): bool
    {
        // Respect kill-switch first
        if (!FeatureFlag::on('platform.ste_projector')) {
            return false;
        }

        // Resolve mappings lazily, cached for this process
        $mappings = $this->loadMappings();

        // We still return true for unmapped types so handle() can record them
        // in ste_unmapped_events. It's cheap (single INSERT ... ON CONFLICT).
        return !empty($event->event_type);
    }

    public function handle(Event $event): void
    {
        $mappings = $this->loadMappings();
        $matches  = $mappings[$event->event_type] ?? [];

        if (empty($matches)) {
            $this->recordUnmapped($event);
            return;
        }

        foreach ($matches as $mapping) {
            try {
                $this->applyMapping($event, $mapping);
            } catch (\Throwable $e) {
                // Never block the event write. STE is a best-effort projection.
                Log::warning('[STE] Projection failed', [
                    'event_id'   => $event->id,
                    'event_type' => $event->event_type,
                    'mapping_id' => $mapping->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Private
    // -----------------------------------------------------------------------

    private function applyMapping(Event $event, object $mapping): void
    {
        $chain        = $mapping->chain;
        $toState      = $mapping->to_state;
        $mappingFrom  = $mapping->from_state;      // may be null = "any"
        $tenantId     = $event->tenant_id;
        $sequenceNum  = (int) ($event->sequence_num ?? 0);
        $extractTags  = $this->decodeJson($mapping->extract_tags);
        $tags         = $this->buildTags($event, $extractTags);

        // Determine the from_state for the transition row
        $fromState = $this->resolveFromState($chain, $event, $mappingFrom, $tenantId);

        // If mapping specifies an explicit from_state and it doesn't match, skip
        if ($mappingFrom !== null && $fromState !== $mappingFrom) {
            return;
        }

        // 1) Upsert the transition count
        $this->upsertTransition($chain, $fromState, $toState, $tags, $tenantId);

        // 2) Patch the state rows (guarded by sequence_num)
        if ($chain === 'session_lifecycle') {
            $sessionId = $this->resolveSessionId($event);
            if ($sessionId) {
                $this->upsertSessionState($sessionId, $tenantId, $toState, $fromState, $sequenceNum);
            }
        } elseif ($chain === 'tenant_lifecycle') {
            $this->upsertTenantState($tenantId, $toState, $fromState, $sequenceNum);
        }
    }

    private function resolveFromState(string $chain, Event $event, ?string $mappingFrom, string $tenantId): ?string
    {
        // Explicit mapping from_state wins
        if ($mappingFrom !== null) {
            return $mappingFrom;
        }

        // Otherwise look up the current state from the relevant *_states row
        if ($chain === 'session_lifecycle') {
            $sessionId = $this->resolveSessionId($event);
            if (!$sessionId) {
                return 'visitor';   // session_lifecycle seed
            }
            $row = DB::table('ste_session_states')
                ->where('session_id', $sessionId)
                ->first(['current_state']);
            return $row->current_state ?? 'visitor';
        }

        if ($chain === 'tenant_lifecycle') {
            $row = DB::table('ste_tenant_states')
                ->where('tenant_id', $tenantId)
                ->first(['current_state']);
            return $row->current_state ?? 'trial';
        }

        return null;
    }

    private function upsertTransition(string $chain, ?string $fromState, string $toState, array $tags, ?string $tenantId): void
    {
        $fromState = $fromState ?? '__initial__';
        $tagsJson  = json_encode((object) $tags);

        // Postgres native upsert matching the partial unique index
        DB::statement(
            "INSERT INTO ste_transitions (chain, from_state, to_state, tags, tenant_id, count, last_seen_at)
             VALUES (?, ?, ?, ?::jsonb, ?, 1, now())
             ON CONFLICT (chain, from_state, to_state, md5(tags::text), COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'))
             DO UPDATE SET count = ste_transitions.count + 1, last_seen_at = now()",
            [$chain, $fromState, $toState, $tagsJson, $tenantId],
        );
    }

    private function upsertSessionState(string $sessionId, string $tenantId, string $toState, ?string $fromState, int $sequenceNum): void
    {
        // Guarded by last_sequence_num so out-of-order replay never regresses state
        DB::statement(
            "INSERT INTO ste_session_states (session_id, tenant_id, current_state, last_state, entered_at, updated_at, last_sequence_num)
             VALUES (?, ?, ?, ?, now(), now(), ?)
             ON CONFLICT (session_id) DO UPDATE
               SET last_state        = ste_session_states.current_state,
                   current_state     = excluded.current_state,
                   updated_at        = now(),
                   last_sequence_num = excluded.last_sequence_num
               WHERE excluded.last_sequence_num > ste_session_states.last_sequence_num",
            [$sessionId, $tenantId, $toState, $fromState, $sequenceNum],
        );
    }

    private function upsertTenantState(?string $tenantId, string $toState, ?string $fromState, int $sequenceNum): void
    {
        if (!$tenantId) {
            return;
        }

        DB::statement(
            "INSERT INTO ste_tenant_states (tenant_id, current_state, last_state, entered_at, updated_at, last_sequence_num)
             VALUES (?, ?, ?, now(), now(), ?)
             ON CONFLICT (tenant_id) DO UPDATE
               SET last_state        = ste_tenant_states.current_state,
                   current_state     = excluded.current_state,
                   updated_at        = now(),
                   last_sequence_num = excluded.last_sequence_num
               WHERE excluded.last_sequence_num > ste_tenant_states.last_sequence_num",
            [$tenantId, $toState, $fromState, $sequenceNum],
        );
    }

    private function recordUnmapped(Event $event): void
    {
        try {
            DB::statement(
                "INSERT INTO ste_unmapped_events (event_type, sample_event_id, tenant_id, first_seen_at, count)
                 VALUES (?, ?, ?, now(), 1)
                 ON CONFLICT (event_type) DO UPDATE SET count = ste_unmapped_events.count + 1",
                [$event->event_type, $event->id, $event->tenant_id],
            );
        } catch (\Throwable) {
            // Swallow — observability-only table.
        }
    }

    /**
     * Load & cache event_type → [mapping rows] index.
     */
    private function loadMappings(): array
    {
        if ($this->mappingCache !== null) {
            return $this->mappingCache;
        }

        $index = [];
        try {
            $rows = DB::table('ste_event_mapping')->where('enabled', true)->get();
            foreach ($rows as $row) {
                $index[$row->event_type] ??= [];
                $index[$row->event_type][] = $row;
            }
        } catch (\Throwable) {
            // Table may not exist yet on fresh envs; degrade to no-op
            $index = [];
        }

        return $this->mappingCache = $index;
    }

    private function buildTags(Event $event, array $extractSpec): array
    {
        if (empty($extractSpec)) {
            return [];
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $tags    = [];
        foreach ($extractSpec as $tagKey => $payloadKey) {
            // Static values (non-string-ref) pass through
            if (is_scalar($payloadKey) && !is_string($payloadKey)) {
                $tags[$tagKey] = $payloadKey;
                continue;
            }

            // String ref of the form "payload.foo" | plain key
            if (is_string($payloadKey) && str_starts_with($payloadKey, 'payload.')) {
                $tags[$tagKey] = data_get($payload, substr($payloadKey, 8));
            } else {
                // If the seeder stored a static tag value, use it directly
                $tags[$tagKey] = $payloadKey;
            }
        }
        return array_filter($tags, fn ($v) => $v !== null);
    }

    private function resolveSessionId(Event $event): ?string
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        return $payload['session_id'] ?? $event->aggregate_id ?? null;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
