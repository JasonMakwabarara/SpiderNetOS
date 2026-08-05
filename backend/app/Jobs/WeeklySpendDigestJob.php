<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\FeatureFlag;
use App\Services\Notifications\NotificationService;
use App\Services\Spend\Accounting\SpendDigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Monday 07:30: deterministic per-tenant weekly spend digest for admins —
 * previous-7-day category totals, top merchants, pending approvals and
 * policy flags. Gated per tenant by the spend.weekly_digest feature flag
 * (Redis/env override) with config('spend.weekly_digest_enabled') as the
 * global default toggle. No LLM in the digest itself; see SpendDigestService
 * for the optional flagged insight sentence.
 */
class WeeklySpendDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 300;

    public function handle(SpendDigestService $digests, NotificationService $notifications): void
    {
        $end = now()->endOfDay();
        $start = now()->subDays(7)->startOfDay();

        $tenants = Tenant::where('status', 'active')
            ->whereNotNull('onboarding_completed_at')
            ->cursor();

        foreach ($tenants as $tenant) {
            if (!$this->digestEnabled($tenant->id)) {
                continue;
            }

            try {
                $digest = $digests->buildDigest($tenant->id, $start, $end);

                // Skip all-quiet tenants to avoid noise.
                if (
                    bccomp($digest['total'], '0', 4) <= 0
                    && $digest['pending_approvals'] === 0
                    && $digest['policy_flag_count'] === 0
                ) {
                    continue;
                }

                $notifications->notifyTenantRole($tenant->id, ['admin', 'super_admin'], 'spend_digest', [
                    'title' => 'Weekly spend digest',
                    'body' => $digests->summaryLine($digest),
                    'url' => '/spend/analytics',
                    'digest' => $digest,
                ]);
            } catch (\Throwable $e) {
                Log::warning('WeeklySpendDigestJob: digest failed for tenant', [
                    'tenant' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function digestEnabled(string $tenantId): bool
    {
        try {
            if (FeatureFlag::on('spend.weekly_digest', $tenantId)) {
                return true;
            }
        } catch (\Throwable) {
            // Flag resolution failure falls through to the config default.
        }

        return (bool) config('spend.weekly_digest_enabled', false);
    }
}
