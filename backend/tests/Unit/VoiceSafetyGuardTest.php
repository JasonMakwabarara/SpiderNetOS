<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ApprovalEngine;
use App\Services\CostGovernor;
use App\Services\FeatureFlag;
use App\Services\VoiceSafetyGuard;
use Mockery;
use Tests\TestCase;

/**
 * VoiceSafetyGuardTest — Phase A
 *
 * Matrix of flag/cost/approval states to verify every gate combination.
 */
class VoiceSafetyGuardTest extends TestCase
{
    private CostGovernor $costGovernor;

    private ApprovalEngine $approvalEngine;

    private VoiceSafetyGuard $guard;

    private const TENANT = 'tenant-uuid-1234';

    protected function setUp(): void
    {
        parent::setUp();

        $this->costGovernor = Mockery::mock(CostGovernor::class);
        $this->approvalEngine = Mockery::mock(ApprovalEngine::class);

        $this->guard = new VoiceSafetyGuard(
            $this->costGovernor,
            new FeatureFlag,
            $this->approvalEngine
        );

        // Default: feature flag on
        FeatureFlag::set('voice.inbound', 'on', self::TENANT);
        FeatureFlag::set('voice.tools', 'on', self::TENANT);
    }

    protected function tearDown(): void
    {
        FeatureFlag::forget('voice.inbound', self::TENANT);
        FeatureFlag::forget('voice.tools', self::TENANT);
        Mockery::close();
        parent::tearDown();
    }

    // ─── checkInbound ────────────────────────────────────────────────────────

    public function test_inbound_allowed_when_flag_on_and_cost_passes(): void
    {
        $this->costGovernor
            ->shouldReceive('canExecute')
            ->once()
            ->andReturn(['allowed' => true, 'degraded' => false]);

        $result = $this->guard->checkInbound(self::TENANT);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
        $this->assertFalse($result['degraded']);
    }

    public function test_inbound_blocked_when_feature_flag_off(): void
    {
        FeatureFlag::set('voice.inbound', 'off', self::TENANT);

        $this->costGovernor->shouldNotReceive('canExecute');

        $result = $this->guard->checkInbound(self::TENANT);

        $this->assertFalse($result['allowed']);
        $this->assertSame('feature_flag_off', $result['reason']);
    }

    public function test_inbound_blocked_when_cost_cap_exceeded(): void
    {
        $this->costGovernor
            ->shouldReceive('canExecute')
            ->once()
            ->andReturn(['allowed' => false, 'degraded' => false, 'reason' => 'budget_exceeded']);

        $result = $this->guard->checkInbound(self::TENANT);

        $this->assertFalse($result['allowed']);
        $this->assertSame('cost_cap_exceeded', $result['reason']);
    }

    public function test_inbound_allowed_with_degraded_flag_when_cost_degraded(): void
    {
        $this->costGovernor
            ->shouldReceive('canExecute')
            ->once()
            ->andReturn(['allowed' => true, 'degraded' => true]);

        $result = $this->guard->checkInbound(self::TENANT);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['degraded']);
    }

    // ─── checkTool ───────────────────────────────────────────────────────────

    public function test_tool_allowed_when_all_gates_pass(): void
    {
        $this->costGovernor
            ->shouldReceive('canExecute')
            ->once()
            ->andReturn(['allowed' => true, 'degraded' => false]);

        $result = $this->guard->checkTool(self::TENANT, 'end_call', [], 'CA001', 'off');

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['awaiting_approval']);
        $this->assertNull($result['approval_id']);
    }

    public function test_tool_blocked_when_tools_flag_off(): void
    {
        FeatureFlag::set('voice.tools', 'off', self::TENANT);
        $this->costGovernor->shouldNotReceive('canExecute');

        $result = $this->guard->checkTool(self::TENANT, 'end_call', [], 'CA001', 'off');

        $this->assertFalse($result['allowed']);
        $this->assertSame('tool_feature_flag_off', $result['reason']);
    }

    public function test_tool_blocked_when_cost_cap_exceeded(): void
    {
        $this->costGovernor
            ->shouldReceive('canExecute')
            ->once()
            ->andReturn(['allowed' => false, 'degraded' => false]);

        $result = $this->guard->checkTool(self::TENANT, 'send_sms', ['message' => 'Hi'], 'CA002', 'off');

        $this->assertFalse($result['allowed']);
        $this->assertSame('cost_cap_exceeded', $result['reason']);
    }

    public function test_transfer_call_requires_approval_under_strict_policy(): void
    {
        $this->costGovernor
            ->shouldReceive('canExecute')
            ->once()
            ->andReturn(['allowed' => true, 'degraded' => false]);

        $this->approvalEngine
            ->shouldReceive('createApproval')
            ->once()
            ->andReturn(['id' => 'approval-uuid']);

        $result = $this->guard->checkTool(
            self::TENANT,
            'transfer_call',
            ['to_number' => '+15559990000'],
            'CA003',
            'strict'
        );

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['awaiting_approval']);
        $this->assertNotNull($result['approval_id']);
        $this->assertSame('awaiting_approval', $result['reason']);
    }

    public function test_send_sms_does_not_require_approval_under_off_policy(): void
    {
        $this->costGovernor
            ->shouldReceive('canExecute')
            ->once()
            ->andReturn(['allowed' => true, 'degraded' => false]);

        $this->approvalEngine->shouldNotReceive('createApproval');

        $result = $this->guard->checkTool(
            self::TENANT,
            'send_sms',
            ['message' => 'Hello', 'to_number' => '+15559990000'],
            'CA004',
            'off'
        );

        $this->assertTrue($result['allowed']);
        $this->assertFalse($result['awaiting_approval']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function test_sms_cost_estimate_scales_with_segments(): void
    {
        $shortCost = $this->guard->estimateSmsCost('Hi');
        $longMsg = str_repeat('A', 321); // 3 segments
        $longCost = $this->guard->estimateSmsCost($longMsg);

        $this->assertEqualsWithDelta($shortCost * 3, $longCost, 0.0001);
    }
}
