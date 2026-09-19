<?php

declare(strict_types=1);

namespace Tests\Feature\Launch;

use App\Models\BrainFile;
use App\Models\BusinessLaunch;
use App\Services\Launch\BusinessLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Generation → approval → live (plan D7 §5).
 *
 * The Python service (intelligence/services/business_launch/app.py) is faked:
 * LaunchArtefacts talks to it over Http:: exactly so this path is testable
 * without a running FastAPI. Numbers in the plan come only from that model.
 */
class LaunchApprovalTest extends LaunchTestCase
{
    use RefreshDatabase;

    private const SUMMARY = [
        'year1_revenue' => 31000.0,
        'year1_ebitda' => 2400.0,
        'year1_gross_margin_pct' => 60.0,
        'cash_low_point' => 4200.0,
        'cash_low_month' => 4,
        'runway_months' => 18,
        'breakeven_month' => 9,
        'months' => 36,
        'years' => [['year' => 1, 'revenue' => 31000.0]],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->flags(['launch.docgen' => 'on']);
    }

    private function fakeService(): void
    {
        Http::fake([
            '*/finance/model' => Http::response([
                'assumptions' => ['currency' => 'GBP', 'starting_cash' => 10000.0],
                'summary' => self::SUMMARY,
                'monthly' => [],
                'formulas_verified' => true,
                'verification' => ['verified' => true],
                'workbook' => [
                    'format' => 'xlsx',
                    'available' => true,
                    'bytes' => 9,
                    'sha256' => str_repeat('a', 64),
                    'b64' => base64_encode('xlsx-bytes'),
                ],
                'disclaimer' => 'Not legal or financial advice.',
            ]),
            '*/docgen/render' => Http::response([
                'template' => 'business-plan.md.j2',
                'markdown' => "# Tidy Tuesdays\n\nYear one revenue: GBP 31,000.\n\n_Not legal or financial advice._\n",
                'formats' => [
                    'md' => ['ok' => true, 'reason' => null],
                    'docx' => ['ok' => true, 'reason' => null],
                    'pdf' => ['ok' => false, 'reason' => 'weasyprint is not installed'],
                ],
                'docx_b64' => base64_encode('docx-bytes'),
                'disclaimer' => 'Not legal or financial advice.',
            ]),
        ]);
    }

    public function test_generate_builds_the_model_and_the_plan_and_stores_the_deliverables(): void
    {
        $this->fakeService();
        $this->startLaunch('uk');
        $this->answerWholeInterview();

        $data = $this->actingAsOwner()
            ->postJson('/api/launch/generate', ['targets' => ['research', 'finance', 'plan']])
            ->assertOk()
            ->json('data');

        $this->assertTrue($data['results']['finance']['ok']);
        $this->assertTrue($data['results']['finance']['formulas_verified']);
        $this->assertTrue($data['results']['plan']['ok']);
        $this->assertSame('founder_guess', $data['results']['research']['research_status']);
        $this->assertSame(BusinessLaunch::STATUS_DRAFTED, $data['state']['status']);
        $this->assertSame(100, $data['state']['progress_pct']);

        // The assumptions we sent are the ones parsed out of the answers.
        Http::assertSent(function (ClientRequest $request) {
            if (! str_contains($request->url(), '/finance/model')) {
                return false;
            }
            $body = $request->data();

            return $body['assumptions']['currency'] === 'GBP'
                && (float) $body['assumptions']['starting_cash'] === 10000.0
                && (float) $body['assumptions']['cost_of_sale_pct'] === 40.0
                && (float) $body['assumptions']['monthly_growth_pct'] === 10.0;
        });

        // Generated prose is written as `agent`, never as the founder.
        $summary = BrainFile::forTenant($this->tenant->id)->where('path', 'finance/summary.md')->first();
        $this->assertNotNull($summary);
        $this->assertSame(BrainFile::SOURCE_AGENT, $summary->source);
        $this->assertStringContainsString('GBP 31,000', $summary->content);
        $this->assertStringContainsString('Not legal or financial advice', $summary->content);

        $plan = BrainFile::forTenant($this->tenant->id)->where('path', 'plan/business-plan.md')->first();
        $this->assertNotNull($plan);
        $this->assertSame(BrainFile::SOURCE_AGENT, $plan->source);
        $this->assertStringContainsString('Not legal or financial advice', $plan->content);

        $launch = BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
        Storage::disk('local')->assertExists('launch/'.$this->tenant->id.'/'.$launch->id.'/model.xlsx');
        Storage::disk('local')->assertExists('launch/'.$this->tenant->id.'/'.$launch->id.'/business-plan.docx');

        $deliverables = collect($this->actingAsOwner()->getJson('/api/launch/deliverables')->assertOk()->json('data'))->keyBy('path');
        $this->assertTrue($deliverables['finance/model.xlsx']['available']);
        $this->assertTrue($deliverables['plan/business-plan.docx']['available']);
        $this->assertFalse($deliverables['plan/business-plan.pdf']['available']);
        $this->assertStringContainsString('weasyprint', $deliverables['plan/business-plan.pdf']['reason']);
    }

    public function test_submitting_without_a_plan_is_refused(): void
    {
        $this->startLaunch('uk');
        $this->answerWholeInterview();

        $this->actingAsOwner()->postJson('/api/launch/submit')->assertStatus(422);
        $this->assertSame(BusinessLaunch::STATUS_MODELLING, BusinessLaunch::forTenant($this->tenant->id)->firstOrFail()->status);
    }

    public function test_submit_creates_a_business_plan_approval_and_approving_it_takes_the_launch_live(): void
    {
        $this->fakeService();
        $this->startLaunch('uk');
        $this->answerWholeInterview();
        $this->actingAsOwner()->postJson('/api/launch/generate', ['targets' => ['finance', 'plan']])->assertOk();

        $submitted = $this->actingAsOwner()->postJson('/api/launch/submit')->assertStatus(201)->json('data');
        $approvalId = $submitted['approval_id'];

        $approval = DB::table('approvals')->where('id', $approvalId)->first();
        $this->assertSame('business_plan', $approval->resource_type);
        $this->assertSame('pending', $approval->status);

        $launch = BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
        $this->assertSame(BusinessLaunch::STATUS_AWAITING_APPROVAL, $launch->status);
        $this->assertSame($approvalId, $launch->approval_id);
        $this->assertSame($launch->id, $approval->resource_id);

        // The real endpoint the cockpit calls fires the config/approvals.php hook.
        $this->actingAsOwner()
            ->postJson("/api/approvals/{$approvalId}/approve", ['reason' => 'Looks right to me.'])
            ->assertOk();

        $launch->refresh();
        $this->assertSame(BusinessLaunch::STATUS_LIVE, $launch->status);
        $this->assertNotNull($launch->went_live_at);
        $this->assertSame(100, app(BusinessLaunchService::class)->progress($launch)['pct']);
    }

    public function test_rejecting_the_plan_sends_the_launch_back_to_drafted(): void
    {
        $this->fakeService();
        $this->startLaunch('uk');
        $this->answerWholeInterview();
        $this->actingAsOwner()->postJson('/api/launch/generate', ['targets' => ['finance', 'plan']])->assertOk();
        $approvalId = $this->actingAsOwner()->postJson('/api/launch/submit')->assertStatus(201)->json('data.approval_id');

        $this->actingAsOwner()
            ->postJson("/api/approvals/{$approvalId}/reject", ['reason' => 'The pricing section is wrong.'])
            ->assertOk();

        $launch = BusinessLaunch::forTenant($this->tenant->id)->firstOrFail();
        $this->assertSame(BusinessLaunch::STATUS_DRAFTED, $launch->status);
        $this->assertNull($launch->went_live_at);
    }

    public function test_the_plan_is_not_rendered_while_the_docgen_flag_is_off(): void
    {
        $this->fakeService();
        $this->flags(['launch.docgen' => 'off']);
        $this->startLaunch('uk');
        $this->answerWholeInterview();

        $data = $this->actingAsOwner()
            ->postJson('/api/launch/generate', ['targets' => ['plan']])
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['results']['plan']['ok']);
        $this->assertSame('docgen_disabled', $data['results']['plan']['reason']);
        Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/docgen/render'));
    }

    public function test_an_unreachable_service_is_reported_not_thrown(): void
    {
        Http::fake(['*' => Http::response(['detail' => 'boom'], 500)]);
        $this->startLaunch('uk');
        $this->answerWholeInterview();

        $data = $this->actingAsOwner()
            ->postJson('/api/launch/generate', ['targets' => ['finance']])
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['results']['finance']['ok']);
        $this->assertSame('service_error', $data['results']['finance']['reason']);
    }
}
