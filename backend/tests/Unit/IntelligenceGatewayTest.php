<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IntelligenceGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntelligenceGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_reads_skip_gateway_after_a_connection_failure(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Connection refused');
        });

        $gateway = app(IntelligenceGateway::class);

        $this->assertSame(1, $gateway->getAutonomy('ws')['autonomy_level']);
        $this->assertSame([], $gateway->listRecommendations('ws'));
        $this->assertSame(1, $gateway->getAutonomy('ws')['autonomy_level']);
        $this->assertSame(1, $calls);
    }

    public function test_health_probes_one_path_when_host_refuses_and_recovers_later(): void
    {
        $calls = 0;
        $down = true;
        Http::fake(function () use (&$calls, &$down) {
            $calls++;
            if ($down) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response(['status' => 'ok', 'autonomy_level' => 3]);
        });

        $gateway = app(IntelligenceGateway::class);

        $this->assertSame('unreachable', $gateway->health()['status']);
        $this->assertSame(1, $calls);

        $down = false;

        $this->assertSame('healthy', $gateway->health()['status']);
        $this->assertSame(3, $gateway->getAutonomy('ws')['autonomy_level']);
    }
}
