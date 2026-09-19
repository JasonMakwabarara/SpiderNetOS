<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AgentRun;
use App\Models\NewsletterIssue;
use App\Models\Tenant;
use App\Services\Agents\AgentRunService;
use App\Services\FeatureFlag;
use App\Services\Reports\CustomerNewsletterCadence;
use App\Services\Reports\MondayLetterComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Hourly sweep that starts the customer newsletter (plan D8 #16) on its 12-day
 * slot, at 09:00 in the tenant's own timezone.
 *
 * It starts a *draft*. Nothing is sent here and nothing is scheduled to send:
 * the run produces one `draft_email` artifact which, per the card's
 * `approval.required: always`, becomes an approval at every autonomy level. A
 * newsletter cannot be unsent, so the human stays in the loop permanently.
 */
class CustomerNewsletterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public const SEND_HOUR = 9;

    public const SKILL = 'customer-newsletter';

    public function __construct(private readonly ?string $onlyTenantId = null) {}

    public function handle(CustomerNewsletterCadence $cadence, AgentRunService $runs): void
    {
        $tenants = Tenant::where('status', 'active')->whereNotNull('onboarding_completed_at')
            ->when($this->onlyTenantId !== null, fn ($q) => $q->where('id', $this->onlyTenantId))
            ->get();

        foreach ($tenants as $tenant) {
            $tenantId = (string) $tenant->id;

            if (! FeatureFlag::on('newsletter.customer', $tenantId)) {
                continue;
            }

            $local = now()->setTimezone(MondayLetterComposer::timezoneOf($tenant));
            if ($this->onlyTenantId === null && $local->hour !== self::SEND_HOUR) {
                continue;
            }
            if (! $cadence->isDue($tenantId, $local)) {
                continue;
            }

            $this->draftFor($tenantId, $cadence, $runs, $local);
        }
    }

    private function draftFor(string $tenantId, CustomerNewsletterCadence $cadence, AgentRunService $runs, Carbon $local): void
    {
        $slot = $cadence->nextSlot($tenantId, $local);
        $period = $cadence->periodFor($slot);

        // One issue per slot, whatever the sweep does.
        if (NewsletterIssue::forTenant($tenantId)->ofKind(NewsletterIssue::KIND_CUSTOMER)->where('period', $period)->exists()) {
            return;
        }

        try {
            $run = $runs->start(
                $tenantId,
                self::SKILL,
                [
                    'since' => $cadence->coversSince($tenantId, $slot)->toDateString(),
                    'include_offer' => true,
                ],
                AgentRun::TRIGGER_SCHEDULED,
                'newsletter:'.$period,
            );

            NewsletterIssue::create([
                'tenant_id' => $tenantId,
                'kind' => NewsletterIssue::KIND_CUSTOMER,
                'period' => $period,
                'status' => NewsletterIssue::STATUS_DRAFT,
                'agent_run_id' => $run->id,
                'scheduled_for' => $slot,
                'meta' => ['covers_since' => $cadence->coversSince($tenantId, $slot)->toDateString()],
            ]);

            Log::info('reports.customer_newsletter.drafting', ['tenant_id' => $tenantId, 'period' => $period, 'run_id' => $run->id]);
        } catch (\Throwable $e) {
            Log::error('reports.customer_newsletter.failed', ['tenant_id' => $tenantId, 'period' => $period, 'error' => $e->getMessage()]);
        }
    }
}
