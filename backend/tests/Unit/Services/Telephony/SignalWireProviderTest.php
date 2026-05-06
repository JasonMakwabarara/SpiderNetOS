<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Telephony;

use App\Services\Telephony\SignalWireProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignalWireProviderTest extends TestCase
{
    private function makeProvider(): SignalWireProvider
    {
        return new SignalWireProvider([
            'project_id' => 'test-project-id',
            'auth_token' => 'test-auth-token',
            'space' => 'test.signalwire.com',
            'api_base' => 'https://test.signalwire.com/api/laml/2010-04-01',
            'webhook_url' => 'https://example.com/voice/inbound',
            'status_callback' => 'https://example.com/voice/status',
        ]);
    }

    public function test_initiate_call_success(): void
    {
        Http::fake([
            '*/Calls.json' => Http::response([
                'sid' => 'CAtest123',
                'status' => 'queued',
                'direction' => 'outbound-api',
            ], 200),
        ]);

        $provider = $this->makeProvider();
        $result = $provider->initiateCall('+15555551234', '+15555557890');

        $this->assertNotNull($result);
        $this->assertSame('CAtest123', $result['sid']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame('outbound-api', $result['direction']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://test.signalwire.com/api/laml/2010-04-01/Accounts/test-project-id/Calls.json'
                && $request['To'] === '+15555551234'
                && $request['From'] === '+15555557890';
        });
    }

    public function test_initiate_call_with_custom_url(): void
    {
        Http::fake([
            '*/Calls.json' => Http::response([
                'sid' => 'CAtest456',
                'status' => 'ringing',
                'direction' => 'outbound-api',
            ], 200),
        ]);

        $provider = $this->makeProvider();
        $result = $provider->initiateCall('+15555551234', '+15555557890', [
            'url' => 'https://custom.example.com/webhook',
        ]);

        $this->assertNotNull($result);
        $this->assertSame('CAtest456', $result['sid']);

        Http::assertSent(function ($request) {
            return $request['Url'] === 'https://custom.example.com/webhook';
        });
    }

    public function test_initiate_call_returns_null_on_failure(): void
    {
        Http::fake([
            '*/Calls.json' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        $provider = $this->makeProvider();
        $result = $provider->initiateCall('+15555551234', '+15555557890');

        $this->assertNull($result);
    }

    public function test_initiate_call_returns_null_without_credentials(): void
    {
        $provider = new SignalWireProvider([
            'project_id' => '',
            'auth_token' => '',
        ]);

        $result = $provider->initiateCall('+15555551234', '+15555557890');
        $this->assertNull($result);
    }

    public function test_get_call(): void
    {
        Http::fake([
            '*/Calls/CAtest123.json' => Http::response([
                'sid' => 'CAtest123',
                'status' => 'completed',
                'duration' => 45,
            ], 200),
        ]);

        $provider = $this->makeProvider();
        $result = $provider->getCall('CAtest123');

        $this->assertNotNull($result);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(45, $result['duration']);
    }

    public function test_end_call(): void
    {
        Http::fake([
            '*/Calls/CAtest123.json' => Http::response([
                'sid' => 'CAtest123',
                'status' => 'completed',
            ], 200),
        ]);

        $provider = $this->makeProvider();
        $result = $provider->endCall('CAtest123');

        $this->assertTrue($result);
    }

    public function test_end_call_returns_false_on_failure(): void
    {
        Http::fake([
            '*/Calls/CAtest123.json' => Http::response(['message' => 'Not found'], 404),
        ]);

        $provider = $this->makeProvider();
        $result = $provider->endCall('CAtest123');

        $this->assertFalse($result);
    }

    public function test_list_calls(): void
    {
        Http::fake([
            '*/Calls.json*' => Http::response([
                'calls' => [
                    ['sid' => 'CA1', 'status' => 'completed'],
                    ['sid' => 'CA2', 'status' => 'in-progress'],
                ],
            ], 200),
        ]);

        $provider = $this->makeProvider();
        $calls = $provider->listCalls();

        $this->assertCount(2, $calls);
        $this->assertSame('CA1', $calls[0]['sid']);
    }

    public function test_generate_stream_twiml(): void
    {
        $provider = $this->makeProvider();
        $twiml = $provider->generateStreamTwiML('wss://example.com/voice/stream', [
            'tenant_id' => 'test-tenant',
            'agent_id' => 'voice_receptionist',
        ]);

        $this->assertStringContainsString('<Response>', $twiml);
        $this->assertStringContainsString('<Stream url="wss://example.com/voice/stream">', $twiml);
        $this->assertStringContainsString('<Parameter name="tenant_id" value="test-tenant"/>', $twiml);
        $this->assertStringContainsString('<Parameter name="agent_id" value="voice_receptionist"/>', $twiml);
        $this->assertStringContainsString('</Response>', $twiml);
    }

    public function test_generate_stream_twiml_without_parameters(): void
    {
        $provider = $this->makeProvider();
        $twiml = $provider->generateStreamTwiML('wss://example.com/voice/stream');

        $this->assertStringContainsString('Stream', $twiml);
        $this->assertStringContainsString('wss://example.com/voice/stream', $twiml);
        $this->assertStringNotContainsString('Parameter', $twiml);
    }
}
