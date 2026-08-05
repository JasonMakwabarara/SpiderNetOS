<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessVoiceCallSummary;
use App\Models\VoiceCall;
use App\Models\VoiceCallSummary;
use App\Services\FeatureFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PostCallSummaryTest — Phase C
 *
 * Tests for post-call processing:
 * - Summary job is dispatched after status=completed webhook
 * - Job writes voice_call_summaries row
 * - Feature flag off → job is a no-op
 */
class PostCallSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        FeatureFlag::set('voice.inbound', 'on');
        FeatureFlag::set('voice.post_call_summary', 'on');
    }

    protected function tearDown(): void
    {
        FeatureFlag::forget('voice.inbound');
        FeatureFlag::forget('voice.post_call_summary');
        parent::tearDown();
    }

    private function createCallRecord(string $callSid = 'CAsummary001'): VoiceCall
    {
        $tenant = \App\Models\Tenant::create([
            'id'     => \Illuminate\Support\Str::uuid(),
            'name'   => 'Post-Call Tenant',
            'slug'   => 'postcall-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10)),
            'status' => 'active',
            'plan'   => 'starter',
        ]);

        return VoiceCall::create([
            'tenant_id'    => $tenant->id,
            'call_sid'     => $callSid,
            'phone_number' => '+15555557890',
            'from_number'  => '+15555551234',
            'direction'    => 'inbound',
            'status'       => 'in-progress',
            'started_at'   => now()->subMinutes(3),
            'transcript'   => [
                ['speaker' => 'caller', 'text' => 'Hi, I need help with my account.', 'timestamp' => now()->toIso8601String()],
                ['speaker' => 'agent',  'text' => 'Sure! I can help you with that.', 'timestamp' => now()->toIso8601String()],
            ],
        ]);
    }

    // ─── Job dispatch via webhook ─────────────────────────────────────────────

    public function test_summary_job_dispatched_on_call_completed(): void
    {
        $this->createCallRecord();

        $response = $this->post('/api/voice/status', [
            'CallSid'      => 'CAsummary001',
            'CallStatus'   => 'completed',
            'CallDuration' => 180,
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessVoiceCallSummary::class, function ($job) {
            return $job->callSid === 'CAsummary001';
        });
    }

    public function test_summary_job_not_dispatched_when_call_not_completed(): void
    {
        $this->createCallRecord('CAbusy001');

        $this->post('/api/voice/status', [
            'CallSid'    => 'CAbusy001',
            'CallStatus' => 'busy',
        ]);

        // Busy, no-answer etc. should NOT trigger summary
        Queue::assertNotPushed(ProcessVoiceCallSummary::class);
    }

    // ─── Job execution ────────────────────────────────────────────────────────

    public function test_job_is_noop_when_flag_off(): void
    {
        FeatureFlag::set('voice.post_call_summary', 'off');
        $call = $this->createCallRecord('CAflagnoop001');

        (new ProcessVoiceCallSummary('CAflagnoop001', $call->tenant_id))->handle();

        $this->assertDatabaseMissing('voice_call_summaries', [
            'voice_call_id' => $call->id,
        ]);
    }

    public function test_job_handles_missing_call_gracefully(): void
    {
        // Job with non-existent call_sid should not throw. Tenant id must be a
        // real UUID: Postgres rejects non-uuid text where sqlite wouldn't.
        $job = new ProcessVoiceCallSummary('CAnonexistent999', (string) \Illuminate\Support\Str::uuid());
        $job->handle(); // should not throw
        $this->assertTrue(true);
    }

    public function test_job_creates_summary_row_with_mock_inference(): void
    {
        $call = $this->createCallRecord('CAjobtest001');

        // Mock inference service to return structured response
        $fakeResponseBody = json_encode([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'summary'        => 'Caller needed account help.',
                        'key_points'     => ['Account issue reported'],
                        'follow_up_tasks'=> ['Check account status'],
                        'sentiment'      => 'positive',
                    ]),
                ],
            ]],
        ]);

        \Illuminate\Support\Facades\Http::fake([
            '*/generate' => \Illuminate\Support\Facades\Http::response($fakeResponseBody, 200),
        ]);

        (new ProcessVoiceCallSummary('CAjobtest001', $call->tenant_id))->handle();

        $this->assertDatabaseHas('voice_call_summaries', [
            'voice_call_id' => $call->id,
            'sentiment'     => 'positive',
        ]);

        $summary = VoiceCallSummary::where('voice_call_id', $call->id)->first();
        $this->assertNotNull($summary);
        $this->assertSame('Caller needed account help.', $summary->summary);
        $this->assertIsArray($summary->key_points);
    }
}
