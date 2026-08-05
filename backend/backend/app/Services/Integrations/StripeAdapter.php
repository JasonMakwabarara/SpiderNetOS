<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;

class StripeAdapter
{
    private string $apiKey;
    private string $webhookSecret;

    public function __construct(
        protected readonly string $tenantId,
        protected readonly array  $credentials,
    ) {
        $this->apiKey = $credentials['api_key'] ?? throw new \RuntimeException('Stripe API key required');
        $this->webhookSecret = $credentials['webhook_secret'] ?? '';
    }

    public function createPaymentIntent(
        float $amount,
        string $currency,
        string $customerId = null,
        array $metadata = [],
    ): array {
        $response = Http::withToken($this->apiKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/payment_intents', [
                'amount' => (int) ($amount * 100),
                'currency' => $currency,
                'customer' => $customerId,
                'metadata' => json_encode($metadata),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Stripe API error: " . $response->body());
        }

        return $response->json();
    }

    public function createCustomer(
        string $name,
        string $email = null,
        string $phone = null,
        array $metadata = [],
    ): array {
        $response = Http::withToken($this->apiKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/customers', [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'metadata' => json_encode($metadata),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Stripe API error: " . $response->body());
        }

        return $response->json();
    }

    public function createSubscription(
        string $customerId,
        string $priceId,
        array $metadata = [],
    ): array {
        $response = Http::withToken($this->apiKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/subscriptions', [
                'customer' => $customerId,
                'items[0][price]' => $priceId,
                'metadata' => json_encode($metadata),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException("Stripe API error: " . $response->body());
        }

        return $response->json();
    }

    public function createInvoice(
        string $customerId,
        array $lineItems = [],
        array $metadata = [],
    ): array {
        $invoice = Http::withToken($this->apiKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/invoices', [
                'customer' => $customerId,
                'auto_advance' => true,
                'metadata' => json_encode($metadata),
            ]);

        if ($invoice->failed()) {
            throw new \RuntimeException("Stripe API error: " . $invoice->body());
        }

        $invoiceData = $invoice->json();

        foreach ($lineItems as $item) {
            Http::withToken($this->apiKey)
                ->asForm()
                ->post('https://api.stripe.com/v1/invoiceitems', [
                    'customer' => $customerId,
                    'invoice' => $invoiceData['id'],
                    'amount' => (int) ($item['amount'] * 100),
                    'currency' => $item['currency'] ?? 'usd',
                    'description' => $item['description'],
                ]);
        }

        $finalize = Http::withToken($this->apiKey)
            ->asForm()
            ->post("https://api.stripe.com/v1/invoices/{$invoiceData['id']}/finalize");

        return $finalize->json();
    }

    public function handleWebhook(string $payload, string $signature): array
    {
        if (!$this->webhookSecret) {
            throw new \RuntimeException('Stripe webhook secret not configured');
        }

        $event = \Stripe\Webhook::constructEvent(
            $payload,
            $signature,
            $this->webhookSecret
        );

        return match ($event->type) {
            'payment_intent.succeeded' => [
                'type' => 'payment_succeeded',
                'payment_intent_id' => $event->data->object->id,
                'amount' => $event->data->object->amount_received / 100,
            ],
            'payment_intent.payment_failed' => [
                'type' => 'payment_failed',
                'payment_intent_id' => $event->data->object->id,
                'error' => $event->data->object->last_payment_error->message,
            ],
            'invoice.paid' => [
                'type' => 'invoice_paid',
                'invoice_id' => $event->data->object->id,
                'amount' => $event->data->object->amount_paid / 100,
            ],
            'invoice.payment_failed' => [
                'type' => 'invoice_payment_failed',
                'invoice_id' => $event->data->object->id,
            ],
            'customer.subscription.created' => [
                'type' => 'subscription_created',
                'subscription_id' => $event->data->object->id,
            ],
            'customer.subscription.deleted' => [
                'type' => 'subscription_cancelled',
                'subscription_id' => $event->data->object->id,
            ],
            default => ['type' => $event->type, 'data' => $event->data->object],
        };
    }

    public function verifyPayment(string $paymentIntentId): array
    {
        $response = Http::withToken($this->apiKey)
            ->get("https://api.stripe.com/v1/payment_intentions/{$paymentIntentId}");

        if ($response->failed()) {
            throw new \RuntimeException("Stripe API error: " . $response->body());
        }

        $data = $response->json();

        return [
            'id' => $data['id'],
            'status' => $data['status'],
            'amount' => $data['amount_received'] / 100,
            'currency' => $data['currency'],
            'customer_id' => $data['customer'],
            'created' => $data['created'],
        ];
    }

    public function refundPayment(string $paymentIntentId, float $amount = null): array
    {
        $params = ['payment_intent' => $paymentIntentId];
        if ($amount) {
            $params['amount'] = (int) ($amount * 100);
        }

        $response = Http::withToken($this->apiKey)
            ->asForm()
            ->post('https://api.stripe.com/v1/refunds', $params);

        if ($response->failed()) {
            throw new \RuntimeException("Stripe API error: " . $response->body());
        }

        return $response->json();
    }
}
