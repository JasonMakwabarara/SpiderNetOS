<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\EventStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies verified Dodo webhook events to the tenant + subscription state.
 *
 * Mirrors the Hannah AI DodoWebhookListener event map. Every state change
 * is appended to the event log so billing history replays like everything
 * else in SpiderNetOS.
 */
class DodoSubscriptionFulfiller
{
    public function __construct(
        private readonly DodoPaymentsService $dodo,
        private readonly EventStore $eventStore,
    ) {}

    public function handle(array $payload): void
    {
        $type = $payload['type'] ?? null;

        if (! is_string($type)) {
            Log::warning('dodo-> fulfiller: missing event type', ['keys' => array_keys($payload)]);

            return;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        match ($type) {
            'payment.succeeded',
            'subscription.active',
            'subscription.renewed' => $this->activate($type, $data),
            'subscription.on_hold',
            'payment.failed' => $this->hold($type, $data),
            'subscription.cancelled',
            'subscription.expired' => $this->cancel($type, $data),
            default => Log::info('dodo-> fulfiller: ignored event', ['type' => $type]),
        };
    }

    private function activate(string $type, array $data): void
    {
        [$tenant, $metadata] = $this->resolveTenant($data);

        if (! $tenant) {
            return;
        }

        $planId = $metadata['plan_id']
            ?? $this->dodo->planIdForProduct($data['product_id'] ?? null);

        if (! $planId || ! is_array(config("dodo.plans.{$planId}"))) {
            Log::warning('dodo-> fulfiller: cannot resolve plan', [
                'tenant_id' => (string) $tenant->id,
                'type' => $type,
            ]);

            return;
        }

        $plan = $this->dodo->plan($planId);
        $providerSubscriptionId = $this->subscriptionId($data);
        $periodEnd = $this->parseDate($data['next_billing_date'] ?? $data['current_period_end'] ?? null)
            ?? Carbon::now()->addMonth();

        DB::transaction(function () use ($tenant, $planId, $plan, $providerSubscriptionId, $data, $periodEnd, $type) {
            $subscription = Subscription::query()->firstOrNew([
                'tenant_id' => (string) $tenant->id,
                'provider' => DodoPaymentsService::GATEWAY_CODE,
                'provider_subscription_id' => $providerSubscriptionId,
            ]);

            $subscription->fill([
                'plan_id' => $planId,
                'plan_name' => $plan['label'],
                'status' => 'active',
                'amount' => $plan['price_usd'] ?? 0,
                'currency' => strtoupper((string) ($data['currency'] ?? 'USD')),
                'interval' => $plan['interval'],
                'current_period_start' => Carbon::now(),
                'current_period_end' => $periodEnd,
                'cancelled_at' => null,
                'ends_at' => null,
                'metadata' => ['source' => $type],
            ])->save();

            $tenant->forceFill([
                'plan' => $planId,
                'status' => 'active',
                'subscribed_at' => $tenant->subscribed_at ?? Carbon::now(),
                'limits' => $plan['limits'],
            ])->save();

            $this->eventStore->append(
                (string) $tenant->id,
                'billing',
                (string) $subscription->id,
                'billing.subscription.activated',
                [
                    'plan_id' => $planId,
                    'provider' => DodoPaymentsService::GATEWAY_CODE,
                    'provider_subscription_id' => $providerSubscriptionId,
                    'period_end' => $periodEnd->toIso8601String(),
                    'source_event' => $type,
                ],
            );
        });

        Log::info('dodo-> fulfiller: subscription activated', [
            'tenant_id' => (string) $tenant->id,
            'plan_id' => $planId,
            'subscription_id' => $providerSubscriptionId,
        ]);
    }

    private function hold(string $type, array $data): void
    {
        [$tenant] = $this->resolveTenant($data);

        if (! $tenant) {
            return;
        }

        DB::transaction(function () use ($tenant, $data, $type) {
            $subscription = $this->findSubscription($tenant, $data);
            $subscription?->fill(['status' => 'past_due'])->save();

            $tenant->forceFill(['status' => 'past_due'])->save();

            $this->eventStore->append(
                (string) $tenant->id,
                'billing',
                (string) ($subscription?->id ?? $tenant->id),
                'billing.subscription.on_hold',
                ['source_event' => $type],
            );
        });
    }

    private function cancel(string $type, array $data): void
    {
        [$tenant] = $this->resolveTenant($data);

        if (! $tenant) {
            return;
        }

        DB::transaction(function () use ($tenant, $data, $type) {
            $subscription = $this->findSubscription($tenant, $data);
            $endsAt = $this->parseDate($data['cancelled_at'] ?? null) ?? Carbon::now();

            $subscription?->fill([
                'status' => 'cancelled',
                'cancelled_at' => Carbon::now(),
                'ends_at' => $endsAt,
            ])->save();

            // Tenant keeps access until period end; plan downgrade happens
            // when the period lapses (scheduler) — here we only record state.
            $this->eventStore->append(
                (string) $tenant->id,
                'billing',
                (string) ($subscription?->id ?? $tenant->id),
                'billing.subscription.cancelled',
                ['ends_at' => $endsAt->toIso8601String(), 'source_event' => $type],
            );
        });
    }

    /**
     * @return array{0: ?Tenant, 1: array}
     */
    private function resolveTenant(array $data): array
    {
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $tenantId = $metadata['tenant_id'] ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            Log::warning('dodo-> fulfiller: webhook missing tenant_id metadata', [
                'metadata_keys' => array_keys($metadata),
            ]);

            return [null, $metadata];
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            Log::warning('dodo-> fulfiller: unknown tenant', ['tenant_id' => $tenantId]);
        }

        return [$tenant, $metadata];
    }

    private function findSubscription(Tenant $tenant, array $data): ?Subscription
    {
        $providerSubscriptionId = $this->subscriptionId($data);

        return Subscription::query()
            ->where('tenant_id', (string) $tenant->id)
            ->where('provider', DodoPaymentsService::GATEWAY_CODE)
            ->when(
                $providerSubscriptionId !== null,
                fn ($q) => $q->where('provider_subscription_id', $providerSubscriptionId),
            )
            ->latest('created_at')
            ->first();
    }

    private function subscriptionId(array $data): ?string
    {
        $candidates = [
            $data['subscription_id'] ?? null,
            is_array($data['subscription'] ?? null)
                ? ($data['subscription']['subscription_id'] ?? $data['subscription']['id'] ?? null)
                : null,
            isset($data['payment_id']) ? 'dodo_payment_'.$data['payment_id'] : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
}
