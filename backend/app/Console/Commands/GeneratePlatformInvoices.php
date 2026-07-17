<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\PlatformInvoice;
use App\Models\TenantSubscription;
use App\Services\Billing\UsageBilling;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closes a billing period into a platform_invoice per live subscription:
 * platform fee + metered usage overage (cost above the plan's included
 * allowance, marked up by the plan margin). Idempotent per (tenant, period).
 *
 *   php artisan spidernet:billing:generate-invoices [--period=YYYY-MM] [--dry-run]
 */
class GeneratePlatformInvoices extends Command
{
    protected $signature = 'spidernet:billing:generate-invoices {--period= : Billing month YYYY-MM (default: last month)} {--dry-run}';

    protected $description = 'Generate platform invoices (fee + usage overage) for the given billing month.';

    public function handle(): int
    {
        [$periodStart, $periodEnd] = $this->resolvePeriod();
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf('Billing period %s → %s%s', $periodStart->toDateString(), $periodEnd->toDateString(), $dryRun ? ' (dry-run)' : ''));

        $subs = TenantSubscription::live()->get();
        $count = 0;

        foreach ($subs as $sub) {
            $plan = Plan::find($sub->plan_id);
            if (! $plan) {
                continue;
            }

            $meteredCents = (int) round(100 * (float) DB::table('usage_daily_aggregates')
                ->where('tenant_id', $sub->tenant_id)
                ->whereBetween('date', [$periodStart->toDateString(), $periodEnd->toDateString()])
                ->sum('total_cost'));

            $overageCents = UsageBilling::overageCents($meteredCents, $plan->included_usage_cents, $plan->usage_margin_pct);
            $totalCents = $plan->monthly_fee_cents + $overageCents;

            $this->line(sprintf(
                '  %s  %s  fee %d + overage %d = %d %s (metered %d, incl %d)',
                $sub->tenant_id, $plan->id, $plan->monthly_fee_cents, $overageCents, $totalCents,
                $plan->currency, $meteredCents, $plan->included_usage_cents,
            ));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($sub, $plan, $periodStart, $periodEnd, $meteredCents, $overageCents, $totalCents) {
                $invoice = PlatformInvoice::updateOrCreate(
                    ['tenant_id' => $sub->tenant_id, 'period_start' => $periodStart->toDateString(), 'period_end' => $periodEnd->toDateString()],
                    [
                        'plan_id' => $plan->id,
                        'platform_fee_cents' => $plan->monthly_fee_cents,
                        'included_usage_cents' => $plan->included_usage_cents,
                        'metered_usage_cents' => $meteredCents,
                        'overage_cents' => $overageCents,
                        'total_cents' => $totalCents,
                        'currency' => $plan->currency,
                        'status' => 'open',
                        'issued_at' => now(),
                    ],
                );

                $invoice->lines()->delete();
                $invoice->lines()->create([
                    'kind' => 'platform_fee',
                    'description' => "{$plan->name} plan — monthly fee",
                    'amount_cents' => $plan->monthly_fee_cents,
                ]);
                if ($overageCents > 0) {
                    $invoice->lines()->create([
                        'kind' => 'usage_overage',
                        'description' => "AI usage over included allowance (cost + {$plan->usage_margin_pct}%)",
                        'amount_cents' => $overageCents,
                        'meta' => ['metered_cents' => $meteredCents, 'included_cents' => $plan->included_usage_cents],
                    ]);
                }
            });

            $count++;
        }

        $this->info(($dryRun ? 'Would generate' : 'Generated').": {$count} invoice(s).");

        return self::SUCCESS;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvePeriod(): array
    {
        $opt = $this->option('period');
        $anchor = $opt
            ? Carbon::createFromFormat('Y-m', $opt)->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();

        return [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()];
    }
}
