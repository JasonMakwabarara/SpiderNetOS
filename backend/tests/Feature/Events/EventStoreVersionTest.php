<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Tenant;
use App\Services\EventStore;
use App\Services\EventVersionConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The expected-version contract, on every driver. The concurrent case — the
 * reason the version is read under the sequence lock — is
 * EventAppendRaceTest, which needs Postgres.
 */
class EventStoreVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_expected_version_is_a_typed_conflict_naming_both_versions(): void
    {
        [$store, $tenant, $id] = $this->aggregateAtVersion(2);

        try {
            $store->append($tenant, 'probe', $id, 'probe.appended', [], [], 1);
            $this->fail('A stale expected version must not append.');
        } catch (EventVersionConflict $e) {
            $this->assertSame(['probe', $id, 1, 2], [$e->aggregateType, $e->aggregateId, $e->expected, $e->found]);
            $this->assertInstanceOf(\RuntimeException::class, $e, 'existing RuntimeException handlers still catch it');
        }

        $this->assertSame(2, $store->getEvents('probe', $id)->count(), 'nothing was appended');
    }

    public function test_the_current_expected_version_appends_the_next(): void
    {
        [$store, $tenant, $id] = $this->aggregateAtVersion(2);

        $event = $store->append($tenant, 'probe', $id, 'probe.appended', [], [], 2);

        $this->assertSame(3, (int) $event->version);
    }

    /** @return array{0: EventStore, 1: string, 2: string} */
    private function aggregateAtVersion(int $version): array
    {
        $tenant = (string) Tenant::create([
            'id' => Str::uuid(), 'name' => 'Probe', 'slug' => 'probe-'.Str::lower(Str::random(6)),
            'status' => 'active', 'plan' => 'growth', 'automation_level' => 'assisted', 'settings' => [],
        ])->id;
        $store = app(EventStore::class);
        $id = (string) Str::uuid();
        for ($i = 0; $i < $version; $i++) {
            $store->append($tenant, 'probe', $id, 'probe.appended', []);
        }

        return [$store, $tenant, $id];
    }
}
