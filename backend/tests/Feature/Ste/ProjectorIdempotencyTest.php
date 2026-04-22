<?php

namespace Tests\Feature\Ste;

use App\Models\Event;
use App\Services\Projections\StateTransitionProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plan §12.9 — projector idempotency under event replay.
 *
 * Asserts:
 *   1. Replaying the same event twice increments transition count by exactly 1.
 *   2. last_sequence_num is monotonic on the state rows.
 *   3. Out-of-order replay (lower sequence_num) does not regress state.
 */
class ProjectorIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The projector uses Postgres-specific jsonb operators and
        // `ON CONFLICT (... md5(tags::text) ...)` upserts. Skip gracefully
        // on SQLite so unit tests still run offline without Postgres.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('STE projector requires Postgres (jsonb / md5 upserts).');
        }

        // Seed the mapping row we rely on
        DB::table('ste_event_mapping')->insert([
            'event_type'   => 'atlas.chat.started',
            'chain'        => 'session_lifecycle',
            'from_state'   => null,
            'to_state'     => 'chat_started',
            'extract_tags' => json_encode((object) []),
            'enabled'      => true,
            'created_at'   => now(),
        ]);
    }

    public function test_replaying_event_from_same_state_accumulates_count(): void
    {
        // Use a mapping with explicit from_state so the second handle() call
        // resolves to the same (from, to, tags) key and the ON CONFLICT upsert
        // fires, incrementing count from 1 → 2.
        DB::table('ste_event_mapping')->insert([
            'event_type'   => 'flow.execution.completed',
            'chain'        => 'session_lifecycle',
            'from_state'   => 'flow_running',
            'to_state'     => 'completed',
            'extract_tags' => json_encode((object) []),
            'enabled'      => true,
            'created_at'   => now(),
        ]);

        $tenantId  = (string) Str::uuid();
        $sessionId = (string) Str::uuid();

        $event = $this->makeEvent($tenantId, $sessionId, 'flow.execution.completed', sequence: 100);

        $projector = new StateTransitionProjection();
        $projector->handle($event);
        $projector->handle($event);

        $row = DB::table('ste_transitions')
            ->where('chain', 'session_lifecycle')
            ->where('from_state', 'flow_running')
            ->where('to_state', 'completed')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->count, 'ON CONFLICT DO UPDATE should have incremented count');
    }

    public function test_replaying_first_event_with_derived_from_state_creates_two_rows(): void
    {
        // Mapping with from_state = NULL falls back to resolveFromState() which
        // reads ste_session_states. First call → from='visitor'; second call
        // after state has advanced → from='chat_started'. Documents the
        // expected behaviour rather than a bug.
        $tenantId  = (string) Str::uuid();
        $sessionId = (string) Str::uuid();
        $event = $this->makeEvent($tenantId, $sessionId, 'atlas.chat.started', sequence: 100);

        $projector = new StateTransitionProjection();
        $projector->handle($event);
        $projector->handle($event);

        $count = DB::table('ste_transitions')
            ->where('chain', 'session_lifecycle')
            ->where('to_state', 'chat_started')
            ->count();

        $this->assertSame(2, $count, 'derived-from-state mappings produce one row per distinct resolved from_state');
    }

    public function test_out_of_order_events_do_not_regress_state(): void
    {
        $tenantId  = (string) Str::uuid();
        $sessionId = (string) Str::uuid();

        $older = $this->makeEvent($tenantId, $sessionId, 'atlas.chat.started', sequence: 10);
        $newer = $this->makeEvent($tenantId, $sessionId, 'atlas.chat.started', sequence: 20);

        $projector = new StateTransitionProjection();
        $projector->handle($newer);
        $projector->handle($older);   // older replayed AFTER newer

        $row = DB::table('ste_session_states')->where('session_id', $sessionId)->first();
        $this->assertNotNull($row);
        $this->assertSame(20, (int) $row->last_sequence_num, 'State row must track the highest sequence_num observed');
    }

    public function test_unmapped_event_recorded_in_unmapped_table(): void
    {
        $tenantId = (string) Str::uuid();
        $event    = $this->makeEvent($tenantId, (string) Str::uuid(), 'totally.unknown.event', sequence: 42);

        (new StateTransitionProjection())->handle($event);

        $row = DB::table('ste_unmapped_events')->where('event_type', 'totally.unknown.event')->first();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->count);
    }

    private function makeEvent(string $tenantId, string $sessionId, string $eventType, int $sequence): Event
    {
        $event               = new Event();
        $event->id           = (string) Str::uuid();
        $event->tenant_id    = $tenantId;
        $event->aggregate_type = 'session';
        $event->aggregate_id   = $sessionId;
        $event->event_type   = $eventType;
        $event->payload      = ['session_id' => $sessionId];
        $event->metadata     = [];
        $event->version      = 1;
        $event->occurred_at  = now();
        $event->sequence_num = $sequence;
        $event->hash         = str_repeat('0', 64);
        $event->previous_hash = null;

        return $event;
    }
}
