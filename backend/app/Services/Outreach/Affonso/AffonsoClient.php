<?php

declare(strict_types=1);

namespace App\Services\Outreach\Affonso;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Affonso affiliate REST API (https://docs.affonso.io/api).
 *
 * Auth is a bearer API key minted in the Affonso dashboard. Only the calls the
 * outreach engine needs are wrapped; each throws a RuntimeException carrying the
 * HTTP status on failure so callers can surface it on the integration row.
 */
class AffonsoClient
{
    public const BASE_URL = 'https://api.affonso.io/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $programId = null,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    /** Build from the decrypted connector credentials (see ConnectorRegistry 'affonso'). */
    public static function fromCredentials(array $credentials): self
    {
        $programId = trim((string) ($credentials['program_id'] ?? ''));
        $baseUrl = trim((string) ($credentials['base_url'] ?? ''));

        return new self(
            trim((string) ($credentials['api_key'] ?? '')),
            $programId !== '' ? $programId : null,
            $baseUrl !== '' ? $baseUrl : self::BASE_URL,
        );
    }

    public function configured(): bool
    {
        return $this->apiKey !== '';
    }

    public function programId(): ?string
    {
        return $this->programId;
    }

    /**
     * Cheapest authenticated call: list one affiliate.
     *
     * @return array{ok: bool, error?: string, detail?: array<string, mixed>}
     */
    public function test(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'Missing api_key.'];
        }

        $res = $this->request()->get('/affiliates', ['limit' => 1]);

        if (! $res->successful()) {
            return ['ok' => false, 'error' => $this->errorFrom($res, 'Affonso affiliates list failed.')];
        }

        return [
            'ok' => true,
            'detail' => [
                'program_id' => $this->programId,
                'total_affiliates' => $res->json('meta.total') ?? $res->json('total'),
            ],
        ];
    }

    /**
     * GET /v1/affiliates with the documented filters: page, limit (max 200),
     * partnership_status, search, group_id, program_id, sort, expand.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> decoded body ({data: [...], ...pagination})
     */
    public function listAffiliates(array $query = []): array
    {
        $res = $this->request()->get('/affiliates', $query);
        $this->throwUnlessOk($res, 'Affonso list affiliates failed');

        return (array) ($res->json() ?? []);
    }

    /**
     * The affiliate whose email matches exactly (case-insensitive), or null.
     * Affonso search is a partial match, so the result is filtered here.
     *
     * @return array<string, mixed>|null
     */
    public function findAffiliateByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $body = $this->listAffiliates(['search' => $email, 'limit' => 25]);

        foreach ((array) ($body['data'] ?? []) as $affiliate) {
            if (is_array($affiliate) && mb_strtolower((string) ($affiliate['email'] ?? '')) === $email) {
                return $affiliate;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function getAffiliate(string $affiliateId): ?array
    {
        $res = $this->request()->get('/affiliates/'.rawurlencode($affiliateId));

        if ($res->status() === 404) {
            return null;
        }

        $this->throwUnlessOk($res, 'Affonso get affiliate failed');

        return (array) ($res->json('data') ?? $res->json() ?? []);
    }

    /**
     * POST /v1/affiliates. Idempotent on email: an existing affiliate with the
     * same address is returned (created=false) instead of creating a duplicate.
     *
     * Options: program_id (defaults to the client's), tracking_id, group_id,
     * status (pending|approved|rejected; Affonso defaults to approved),
     * external_user_id, metadata, country_code.
     *
     * @param  array<string, mixed>  $options
     * @return array{created: bool, affiliate: array<string, mixed>}
     */
    public function createAffiliate(string $name, string $email, array $options = []): array
    {
        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('A valid email is required to create an affiliate.');
        }

        $existing = $this->findAffiliateByEmail($email);
        if ($existing !== null) {
            return ['created' => false, 'affiliate' => $existing];
        }

        $name = trim($name);

        $payload = array_filter([
            'name' => mb_substr($name !== '' ? $name : $email, 0, 100),
            'email' => $email,
            'program_id' => $options['program_id'] ?? $this->programId,
            'tracking_id' => $options['tracking_id'] ?? null,
            'group_id' => $options['group_id'] ?? null,
            'status' => $options['status'] ?? 'approved',
            'external_user_id' => $options['external_user_id'] ?? null,
            'metadata' => $options['metadata'] ?? null,
            'country_code' => $options['country_code'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (empty($payload['program_id'])) {
            throw new \RuntimeException('Affonso program_id is required to create an affiliate.');
        }

        $res = $this->request()->post('/affiliates', $payload);
        $this->throwUnlessOk($res, 'Affonso create affiliate failed');

        return ['created' => true, 'affiliate' => (array) ($res->json('data') ?? $res->json() ?? [])];
    }

    /**
     * POST /v1/affiliates/{id}/portal-token: a single-use auto-login URL valid
     * for five minutes. Only for immediate redirects; never put it in an email.
     *
     * @return array<string, mixed> {token, portalUrl, expiresAt}
     */
    public function portalToken(string $affiliateId): array
    {
        $res = $this->request()->post('/affiliates/'.rawurlencode($affiliateId).'/portal-token');
        $this->throwUnlessOk($res, 'Affonso portal token failed');

        return (array) ($res->json('data') ?? $res->json() ?? []);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(20);
    }

    private function throwUnlessOk(Response $res, string $context): void
    {
        if (! $res->successful()) {
            throw new \RuntimeException($this->errorFrom($res, $context));
        }
    }

    private function errorFrom(Response $res, string $fallback): string
    {
        $message = $res->json('error.message') ?? $res->json('message') ?? $res->json('error');
        $message = is_string($message) && $message !== '' ? $message : $fallback;

        return $message.' (HTTP '.$res->status().')';
    }
}
