<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Services\EventStore;
use App\Services\EventVersionConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concurrency\SeparateProcessRaces;
use Tests\TestCase;

/**
 * Two appends to one aggregate, as a real race (see SeparateProcessRaces).
 *
 * An aggregate's next version is derived from the versions already stored, so
 * it is only right if nothing else can store one in between. Appends are
 * serialised by the lock on the single `event_sequence` row; the version has
 * to be read after that lock is held, or two appends both read N, both write
 * N+1, and the unique index on (aggregate_type, aggregate_id, version) fails
 * one of them as an uncontrolled database error. The approval hook appends
 * through here, so this was a release dependency of the approval workflow.
 *
 * The holder takes the sequence lock; both competitors queue behind it having
 * done everything before it; releasing it lets them through in queue order.
 */
#[Group('postgres-only')]
class EventAppendRaceTest extends TestCase
{
    use RefreshDatabase, SeparateProcessRaces {
        SeparateProcessRaces::connectionsToTransact insteadof RefreshDatabase;
    }

    private const EFFECTS = 'race_probe_effects';

    private const SEQUENCE_LOCK = 'select id from event_sequence for update';

    private Tenant $tenant;

    private string $aggregateId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootRaces('Append races need Postgres: SQLite serialises every writer, so nothing here can overlap.');

        $this->tenant = Tenant::create([
            'id' => Str::uuid(), 'name' => 'Append Race', 'slug' => 'append-race-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'automation_level' => 'assisted',
            'onboarding_completed_at' => now(), 'settings' => [],
        ]);
        $this->aggregateId = (string) Str::uuid();

        // Version 1 exists before the race, so both competitors read the same
        // non-empty history.
        app(EventStore::class)->append((string) $this->tenant->id, 'race_probe', $this->aggregateId, 'race_probe.seeded', []);

        DB::statement('create table if not exists '.self::EFFECTS.' (label text not null)');
        DB::table(self::EFFECTS)->delete();
    }

    protected function tearDown(): void
    {
        $this->shutDownRaces();
        if ($this->onPostgres()) {
            DB::statement('drop table if exists '.self::EFFECTS);
        }
        parent::tearDown();
    }

    protected function raceTenantId(): string
    {
        return (string) $this->tenant->id;
    }

    public function test_concurrent_appends_to_one_aggregate_take_consecutive_versions(): void
    {
        $outcomes = $this->race(self::SEQUENCE_LOCK, [], [$this->appendJob(), $this->appendJob()]);

        $this->assertSame(['appended', 'appended'], array_column($outcomes, 'status'), json_encode($outcomes));
        $this->assertSame([2, 3], array_column($outcomes, 'version'), 'in the order they took the lock');
        $this->assertSame([1, 2, 3], $this->versions());
        $this->assertChainIntact();
    }

    /**
     * Both competitors were told version 1 is current. One appends version 2;
     * the other must learn that version 1 is no longer current as a conflict
     * it can act on — not as the unique index's database error — and whatever
     * it wrote in the same transaction must not survive.
     */
    public function test_a_stale_expected_version_is_a_controlled_conflict_that_leaves_nothing_behind(): void
    {
        $outcomes = $this->race(self::SEQUENCE_LOCK, [], [
            $this->appendJob(expected: 1, effect: 'first'),
            $this->appendJob(expected: 1, effect: 'second'),
        ]);

        $this->assertSame(['appended', 2], [$outcomes[0]['status'], $outcomes[0]['version'] ?? null], json_encode($outcomes[0]));
        $this->assertSame('threw', $outcomes[1]['status'], json_encode($outcomes[1]));
        $this->assertStringStartsWith(EventVersionConflict::class.': ', (string) $outcomes[1]['error']);
        $this->assertStringContainsString('expected version 1, found 2', (string) $outcomes[1]['error']);

        $this->assertSame([1, 2], $this->versions());
        $this->assertSame(['first'], DB::table(self::EFFECTS)->pluck('label')->all(), 'the loser\'s business write rolled back with its append');
        $this->assertChainIntact();
    }

    // ------------------------------------------------------------------ //

    /** @return array<string, mixed> */
    private function appendJob(?int $expected = null, ?string $effect = null): array
    {
        return array_filter([
            'mode' => 'append', 'tenant_id' => (string) $this->tenant->id,
            'aggregate_type' => 'race_probe', 'aggregate_id' => $this->aggregateId, 'event_type' => 'race_probe.appended',
            'expected_version' => $expected,
            'effect' => $effect === null ? null : ['table' => self::EFFECTS, 'label' => $effect],
        ], fn ($v) => $v !== null);
    }

    /** @return list<int> */
    private function versions(): array
    {
        return Event::forAggregate('race_probe', $this->aggregateId)->orderBy('version')->pluck('version')->map(fn ($v) => (int) $v)->all();
    }

    /** The tenant's events link to each other in sequence order, with no gap and no fork. */
    private function assertChainIntact(): void
    {
        $events = Event::query()->where('tenant_id', (string) $this->tenant->id)->orderBy('sequence_num')->get(['hash', 'previous_hash']);
        $this->assertNull($events->first()->previous_hash);
        foreach ($events->slice(1)->values() as $i => $event) {
            $this->assertSame($events[$i]->hash, $event->previous_hash, "event {$i}+1 links to event {$i}");
        }
    }
}
