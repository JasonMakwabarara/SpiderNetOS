<?php

declare(strict_types=1);

namespace App\Services\Hannah;

use App\Models\HannahLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureFlag;
use App\Services\TenantKeyManager;
use Illuminate\Support\Facades\Log;

/**
 * "I need to market this new product" (plan D7 §1).
 *
 * The hand-off in one sentence: the owner never types their business into a
 * second product. SpiderNetOS provisions their Hannah workspace, pushes the
 * brand from the Knowledge brain, and hands them a single-use link that lands
 * them inside Hannah already signed in.
 *
 * Three boundaries this service keeps:
 *
 *  - **The tenant owns the Hannah account, not SpiderNet.** One Hannah user
 *    and workspace per tenant, owned by their own admin email. If they ever
 *    leave SpiderNetOS the marketing work is still theirs.
 *  - **Consent is explicit and recorded.** Linking creates an account on a
 *    third-party product under the owner's name; that is not something to do
 *    on their behalf because it would be convenient. `terms_accepted_at` is a
 *    timestamp, not a boolean.
 *  - **The brand only leaves on purpose.** A sync is skipped when the payload
 *    hash is unchanged, so a brain edit that Hannah cannot see never crosses
 *    the boundary.
 */
class HannahHandoffService
{
    public function __construct(
        private readonly HannahClient $client,
        private readonly HannahBrandMapper $mapper,
    ) {}

    public function enabled(string $tenantId): bool
    {
        return FeatureFlag::on('hannah_ai.handoff', $tenantId);
    }

    public function linkFor(string $tenantId): ?HannahLink
    {
        return HannahLink::forTenant($tenantId)->first();
    }

    /**
     * What the cockpit shows on the Hannah card: linked or not, what the brain
     * is still missing, and whether this installation can talk to Hannah at all.
     *
     * @return array<string, mixed>
     */
    public function status(string $tenantId): array
    {
        $link = $this->linkFor($tenantId);

        return [
            'enabled' => $this->enabled($tenantId),
            'configured' => $this->client->configured(),
            'linked' => $link?->isLinked() ?? false,
            'status' => $link?->status ?? 'unlinked',
            'owner_email' => $link?->owner_email,
            'workspace_id' => $link?->hannah_workspace_id,
            'last_brand_synced_at' => $link?->last_brand_synced_at,
            'error' => $link?->error,
            'brand' => $this->mapper->readiness($tenantId),
        ];
    }

    /**
     * Create or re-attach this tenant's Hannah identity.
     *
     * Idempotent by design: calling it twice returns the same link rather than
     * creating a second Hannah account for the same business.
     *
     * @throws HannahNotConfiguredException|HannahApiException
     */
    public function link(string $tenantId, User $actor, bool $termsAccepted): HannahLink
    {
        if (! $this->enabled($tenantId)) {
            throw new HannahNotConfiguredException('The Hannah AI hand-off is not switched on for this workspace.');
        }

        $existing = $this->linkFor($tenantId);
        if ($existing !== null && $existing->isLinked()) {
            return $existing;
        }

        if (! $termsAccepted) {
            // Creating an account on another product under someone's name is
            // not a convenience we extend on their behalf.
            throw new \InvalidArgumentException('Hannah AI\'s terms have to be accepted before an account is created.');
        }

        $tenant = Tenant::find($tenantId);
        $mapped = $this->mapper->map($tenantId);

        $link = $existing ?? new HannahLink(['tenant_id' => $tenantId]);
        $link->fill([
            'linked_by' => (string) $actor->id,
            'owner_email' => (string) $actor->email,
            'status' => HannahLink::STATUS_PENDING,
            'terms_accepted_at' => now(),
            'terms_accepted_by' => (string) $actor->email,
            'error' => null,
        ])->save();

        try {
            // One request id for the whole provisioning attempt: a timeout on
            // the way back must not create a second Hannah account on retry.
            $requestId = 'link:'.$tenantId.':'.$link->id;

            $result = $this->client->provision($tenantId, [
                'owner_email' => (string) $actor->email,
                'owner_name' => (string) $actor->name,
                'workspace_name' => (string) ($tenant?->name ?? 'Workspace'),
                'external_id' => $tenantId,
                'terms_accepted_at' => $link->terms_accepted_at?->toIso8601String(),
                'company' => $mapped['payload'],
            ], $requestId);

            $secretRef = $this->storeWebhookSecret($tenantId, (string) ($result['webhook_secret'] ?? ''));

            $link->forceFill([
                'hannah_user_id' => $this->id($result, 'user_id'),
                'hannah_workspace_id' => $this->id($result, 'workspace_id'),
                'hannah_company_id' => $this->id($result, 'company_id'),
                'open_id' => $this->id($result, 'open_id'),
                'webhook_secret_ref' => $secretRef,
                'status' => HannahLink::STATUS_LINKED,
                // The company was created from this payload, so it is already synced.
                'last_brand_hash' => $mapped['payload'] === [] ? null : $mapped['hash'],
                'last_brand_synced_at' => $mapped['payload'] === [] ? null : now(),
                'meta' => ['missing_at_link' => $mapped['missing']],
            ])->save();

            Log::info('hannah.linked', ['tenant_id' => $tenantId, 'workspace_id' => $link->hannah_workspace_id]);
        } catch (\Throwable $e) {
            $link->forceFill([
                'status' => HannahLink::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            throw $e;
        }

        return $link->fresh();
    }

    /**
     * Push the brand across. Returns what happened and why — `skipped` when
     * nothing Hannah can see has changed since the last push.
     *
     * @return array{synced: bool, reason: string, hash: string|null, missing: list<string>}
     */
    public function syncBrand(string $tenantId, bool $force = false): array
    {
        $link = $this->linkFor($tenantId);

        if ($link === null || ! $link->isLinked()) {
            return ['synced' => false, 'reason' => 'not_linked', 'hash' => null, 'missing' => []];
        }

        $mapped = $this->mapper->map($tenantId);

        if ($mapped['payload'] === []) {
            return ['synced' => false, 'reason' => 'nothing_to_send', 'hash' => null, 'missing' => $mapped['missing']];
        }

        if (! $force && $link->last_brand_hash === $mapped['hash']) {
            return ['synced' => false, 'reason' => 'unchanged', 'hash' => $mapped['hash'], 'missing' => $mapped['missing']];
        }

        $this->client->pushBrand(
            $tenantId,
            (string) $link->hannah_user_id,
            $mapped['payload'],
            'brand:'.$tenantId.':'.$mapped['hash'],
        );

        $link->forceFill([
            'last_brand_hash' => $mapped['hash'],
            'last_brand_synced_at' => now(),
            'error' => null,
        ])->save();

        Log::info('hannah.brand_synced', ['tenant_id' => $tenantId, 'fields' => array_keys($mapped['payload'])]);

        return ['synced' => true, 'reason' => 'pushed', 'hash' => $mapped['hash'], 'missing' => $mapped['missing']];
    }

    /**
     * The link that lands the owner inside Hannah, signed in, on the page they
     * asked for. Single-use and short-lived on Hannah's side.
     *
     * @return array{url: string, expires_in: int}
     */
    public function deepLink(string $tenantId, string $redirect = '/'): array
    {
        $link = $this->linkFor($tenantId);

        if ($link === null || ! $link->isLinked()) {
            throw new HannahNotConfiguredException('This workspace is not linked to Hannah AI yet.');
        }

        // An open redirect here would hand an attacker a signed-in session on
        // another product, so only in-app paths are ever forwarded.
        $redirect = self::safeRedirect($redirect);

        return $this->client->deepLink($tenantId, (string) $link->hannah_user_id, $redirect);
    }

    /**
     * A relative, single-segment-rooted path, or "/".
     *
     * Rejects absolute URLs, protocol-relative "//evil.example", backslash
     * tricks and anything with a scheme.
     */
    public static function safeRedirect(string $redirect): string
    {
        $redirect = trim($redirect);

        if ($redirect === '' || $redirect === '/') {
            return '/';
        }
        if (str_contains($redirect, '\\') || str_contains($redirect, "\n") || str_contains($redirect, "\r")) {
            return '/';
        }
        if (! str_starts_with($redirect, '/') || str_starts_with($redirect, '//')) {
            return '/';
        }
        if (preg_match('#^/+\w+:#', $redirect)) {
            return '/';
        }

        return mb_substr($redirect, 0, 300);
    }

    // ------------------------------------------------------------------ //

    /**
     * Keep the webhook secret in the key manager, and only its reference in
     * the row — a table dump should not be enough to forge Hannah's webhooks.
     */
    private function storeWebhookSecret(string $tenantId, string $secret): ?string
    {
        if ($secret === '') {
            return null;
        }

        $ref = 'hannah:webhook:'.$tenantId;

        try {
            app(TenantKeyManager::class)->storeSecret($tenantId, $ref, $secret);

            return $ref;
        } catch (\Throwable $e) {
            // A secret we cannot store safely is a secret we do not keep. The
            // link still works; webhook verification is simply unavailable
            // until the next link attempt, which is the safe failure.
            Log::warning('hannah.webhook_secret_store_failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function id(array $result, string $key): ?string
    {
        $value = $result[$key] ?? ($result['data'][$key] ?? null);

        return is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 64) : null;
    }
}
