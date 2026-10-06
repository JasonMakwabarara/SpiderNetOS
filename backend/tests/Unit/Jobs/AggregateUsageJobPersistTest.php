<?php

namespace Tests\Unit\Jobs;

use App\Jobs\AggregateUsageJob;
use App\Models\Tenant;
use App\Services\EventStore;
use App\Services\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class AggregateUsageJobPersistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $driver = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? 'sqlite');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('AggregateUsageJob persist test requires PostgreSQL.');
        }

        parent::setUp();
    }

    public function test_job_writes_signed_usage_aggregate_persisted_event(): void
    {
        $tenant = Tenant::create([
            'name' => 'Usage Aggregate',
            'slug' => 'usage-aggregate-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'plan' => 'pro',
        ]);
        $date = now()->toDateString();

        try {
            FeatureFlag::set('atlas.usage_aggregates_v2', 'on');
        } catch (\Throwable) {
            config(['features.atlas.usage_aggregates_v2' => 'on']);
        }

        $recorded = app(EventStore::class)->append(
            $tenant->id,
            'usage',
            (string) Str::uuid(),
            'usage.recorded',
            [
                'resource_type' => 'inference',
                'cost_usd' => 1.5,
                'tokens_input' => 10,
                'tokens_output' => 5,
            ],
        );

        (new AggregateUsageJob($date))->handle();

        $event = DB::table('event_log')
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'usage.aggregate.persisted')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('usage_aggregate', $event->aggregate_type);
        $this->assertSame(
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'spidernet:usage_aggregate:'.$tenant->id.':'.$date.':inference')->toString(),
            $event->aggregate_id,
        );
        $this->assertSame(1, (int) $event->version);
        $this->assertGreaterThan((int) $recorded->sequence_num, (int) $event->sequence_num);
        $this->assertNotSame('', (string) $event->hash);
        $this->assertSame($recorded->hash, $event->previous_hash);

        $payload = is_string($event->payload) ? json_decode($event->payload, true) : (array) json_decode(json_encode($event->payload), true);
        $this->assertSame('2.0.0', $payload['schema_version']);
        $this->assertSame(1, (int) $payload['total_calls']);
        $this->assertSame(15, (int) $payload['total_tokens']);
        $this->assertEquals(1.5, (float) $payload['total_cost']);

        $aggregate = DB::table('usage_daily_aggregates')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($aggregate);
        $this->assertSame(1, (int) $aggregate->total_calls);
        $this->assertSame(15, (int) $aggregate->total_tokens);
        $this->assertEquals(1.5, (float) $aggregate->total_cost);
    }
}
