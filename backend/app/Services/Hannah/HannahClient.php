<?php

declare(strict_types=1);

namespace App\Services\Hannah;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Speaks Hannah AI's partner API (plan D7 §1).
 *
 * The contract, as agreed with Hannah's side:
 *   POST /api/partner/token          assertion -> short-lived bearer
 *   POST /api/partner/users          create-or-link a user + workspace + Company + wallet
 *   PUT  /api/partner/users/{id}/brand   push the brand payload (+ assets by URL)
 *   POST /api/partner/deep-link      single-use SSO token -> /auth/partner?token=&redirect=
 *   GET  /api/partner/wallet         credit balance for the linked workspace
 *
 * Three things this client is careful about:
 *
 *  - **Idempotency.** Every write carries X-Request-Id. Provisioning a user is
 *    the one call that must never run twice, and a network timeout on the way
 *    back is indistinguishable from a failure on the way there.
 *  - **Token caching.** The bearer is cached per tenant for slightly less than
 *    its lifetime. Without this, a brand sync across twenty tenants would mint
 *    twenty assertions a minute and look exactly like an attack.
 *  - **Never logging the payload.** Brand payloads carry the tenant's
 *    positioning and customer list; the log records the endpoint, the status
 *    and the request id, and nothing else.
 */
class HannahClient
{
    public function __construct(private readonly HannahPartnerToken $tokens) {}

    public function configured(): bool
    {
        return trim((string) config('services.hannah.url', '')) !== '' && $this->tokens->configured();
    }

    /**
     * Create or link this tenant's Hannah user, workspace and Company.
     *
     * @param  array<string, mixed>  $payload  owner_email, owner_name, company{...}, terms_accepted_at
     * @return array<string, mixed> user_id, workspace_id, company_id, open_id, webhook_secret
     */
    public function provision(string $tenantId, array $payload, ?string $requestId = null): array
    {
        return $this->send($tenantId, 'post', '/api/partner/users', $payload, $requestId);
    }

    /**
     * Push the brand to Hannah's Company record.
     *
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    public function pushBrand(string $tenantId, string $hannahUserId, array $brand, ?string $requestId = null): array
    {
        return $this->send($tenantId, 'put', "/api/partner/users/{$hannahUserId}/brand", $brand, $requestId);
    }

    /**
     * A single-use SSO token. The owner follows it and arrives inside Hannah
     * already signed in, on the page they asked for.
     *
     * @return array{url: string, expires_in: int}
     */
    public function deepLink(string $tenantId, string $hannahUserId, string $redirect = '/'): array
    {
        $response = $this->send($tenantId, 'post', '/api/partner/deep-link', [
            'user_id' => $hannahUserId,
            'redirect' => $redirect,
        ]);

        return [
            'url' => (string) ($response['url'] ?? ''),
            'expires_in' => (int) ($response['expires_in'] ?? 60),
        ];
    }

    /** @return array<string, mixed> */
    public function wallet(string $tenantId, string $hannahWorkspaceId): array
    {
        return $this->send($tenantId, 'get', '/api/partner/wallet', ['workspace_id' => $hannahWorkspaceId]);
    }

    // ------------------------------------------------------------------ //
    //  Transport
    // ------------------------------------------------------------------ //

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $tenantId, string $method, string $path, array $payload = [], ?string $requestId = null): array
    {
        if (! $this->configured()) {
            throw new HannahNotConfiguredException('Hannah AI is not configured on this installation.');
        }

        $requestId ??= (string) Str::uuid();

        $request = $this->request($tenantId)->withHeaders(['X-Request-Id' => $requestId]);

        /** @var Response $response */
        $response = $method === 'get'
            ? $request->get($path, $payload)
            : $request->{$method}($path, $payload);

        // The payload is never logged: it carries the tenant's positioning.
        Log::info('hannah.partner_call', [
            'tenant_id' => $tenantId,
            'method' => strtoupper($method),
            'path' => $path,
            'status' => $response->status(),
            'request_id' => $requestId,
        ]);

        if ($response->status() === 401) {
            // The bearer went stale early (Hannah restarted, key rotated).
            // Drop it and let the next attempt mint a fresh one rather than
            // failing a brand sync on a cache artefact.
            Cache::forget($this->tokenCacheKey($tenantId));
        }

        if ($response->failed()) {
            $body = is_array($response->json()) ? $response->json() : [];

            throw new HannahApiException(
                'Hannah AI refused '.strtoupper($method).' '.$path.' ('.$response->status().'): '
                    .mb_substr((string) ($body['message'] ?? $response->body()), 0, 300),
                $response->status(),
                $body,
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function request(string $tenantId): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.hannah.url'), '/'))
            ->timeout((int) config('services.hannah.timeout', 30))
            ->acceptJson()
            ->withToken($this->bearer($tenantId));
    }

    /** Exchange the assertion for a bearer, cached just short of its lifetime. */
    public function bearer(string $tenantId): string
    {
        $key = $this->tokenCacheKey($tenantId);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $assertion = $this->tokens->assertionFor($tenantId);

        $response = Http::baseUrl(rtrim((string) config('services.hannah.url'), '/'))
            ->timeout((int) config('services.hannah.timeout', 30))
            ->acceptJson()
            ->post('/api/partner/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if ($response->failed()) {
            throw new HannahApiException(
                'Hannah AI would not exchange the partner assertion ('.$response->status().').',
                $response->status(),
                is_array($response->json()) ? $response->json() : [],
            );
        }

        $token = (string) $response->json('access_token', '');
        if ($token === '') {
            throw new HannahApiException('Hannah AI returned no access_token.', 502);
        }

        // Respect Hannah's own expiry when it gives one, and always stop short
        // of it so a request never starts with a token that expires mid-flight.
        $ttl = (int) $response->json('expires_in', (int) config('services.hannah.token_ttl_seconds', 900));
        Cache::put($key, $token, max(30, $ttl - 30));

        return $token;
    }

    public function forgetToken(string $tenantId): void
    {
        Cache::forget($this->tokenCacheKey($tenantId));
    }

    private function tokenCacheKey(string $tenantId): string
    {
        return 'hannah:partner_token:'.$tenantId;
    }
}
