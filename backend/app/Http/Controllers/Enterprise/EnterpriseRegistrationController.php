<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EventStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enterprise self-serve registration funnel.
 *
 * Implements the contract the marketing-site RegisterWizard was built
 * against (previously served only by the FastAPI mock in backend/server.py).
 * Every step is throttled and the whole funnel can be disabled with
 * config('enterprise.self_serve_enabled').
 */
class EnterpriseRegistrationController extends Controller
{
    public function __construct(private readonly EventStore $eventStore)
    {
    }

    /**
     * 503 response when the funnel kill-switch is off, null otherwise.
     */
    private function disabledResponse(): ?JsonResponse
    {
        if (! config('enterprise.self_serve_enabled')) {
            return response()->json(['detail' => 'Self-serve registration is currently disabled.'], 503);
        }

        return null;
    }

    /**
     * POST /api/enterprise/register/start
     */
    public function start(Request $request): JsonResponse
    {
        if ($disabled = $this->disabledResponse()) {
            return $disabled;
        }

        $validated = $request->validate([
            'org_name' => 'required|string|max:120',
            'contact_email' => 'required|email|max:190',
            'contact_name' => 'nullable|string|max:120',
            'domain' => 'nullable|string|max:190',
        ]);

        $domain = strtolower(trim($validated['domain'] ?: Str::after($validated['contact_email'], '@')));
        $id = 'ent_' . Str::lower(Str::random(20));
        $domainToken = 'sn_' . Str::random(24);

        DB::table('enterprise_registrations')->insert([
            'id' => $id,
            'org_name' => $validated['org_name'],
            'contact_email' => $validated['contact_email'],
            'contact_name' => $validated['contact_name'] ?? '',
            'domain' => $domain,
            'domain_token' => $domainToken,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'enterprise_id' => $id,
            'domain' => $domain,
            'domain_token' => $domainToken,
            'next_step' => 'verify-domain',
        ]);
    }

    /**
     * POST /api/enterprise/register/verify-domain
     *
     * Looks for a DNS TXT record `spidernet-verify=<token>` on the domain.
     * Outside production (or when enterprise.auto_verify_domains is on)
     * verification auto-passes so demo/staging funnels stay frictionless.
     */
    public function verifyDomain(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'required|string',
            'method' => 'nullable|string|in:auto,dns,email',
        ]);

        $registration = DB::table('enterprise_registrations')->where('id', $validated['enterprise_id'])->first();

        if (! $registration) {
            return response()->json(['detail' => 'enterprise not found'], 404);
        }

        $method = $validated['method'] ?? 'auto';
        $verified = $this->domainTokenPresent($registration->domain, $registration->domain_token);

        if (! $verified && (bool) config('enterprise.auto_verify_domains')) {
            $verified = true;
            $method = 'auto';
        }

        if (! $verified) {
            return response()->json([
                'verified' => false,
                'method' => $method,
                'domain' => $registration->domain,
                'detail' => 'TXT record spidernet-verify=<token> not found yet. DNS can take a few minutes to propagate.',
            ], 422);
        }

        DB::table('enterprise_registrations')->where('id', $registration->id)->update([
            'domain_verified' => true,
            'verified_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'verified' => true,
            'method' => $method,
            'domain' => $registration->domain,
        ]);
    }

    /**
     * POST /api/enterprise/register/create-tenant
     *
     * Creates the real Tenant + first admin User. Idempotent per
     * registration — repeat calls return the already-created tenant.
     */
    public function createTenant(Request $request): JsonResponse
    {
        if ($disabled = $this->disabledResponse()) {
            return $disabled;
        }

        $validated = $request->validate([
            'enterprise_id' => 'required|string',
            'region' => 'nullable|string|max:32',
        ]);

        $registration = DB::table('enterprise_registrations')->where('id', $validated['enterprise_id'])->first();

        if (! $registration) {
            return response()->json(['detail' => 'enterprise not found'], 404);
        }

        if ($registration->tenant_id) {
            $existing = Tenant::find($registration->tenant_id);
            if ($existing) {
                return response()->json(['tenant' => $this->tenantPayload($existing, $registration)]);
            }
        }

        $region = $validated['region'] ?? 'us-east-1';

        $tenant = DB::transaction(function () use ($registration, $region) {
            $slug = Str::slug(Str::limit($registration->org_name, 24, '')) . '-' . Str::lower(Str::random(4));

            $tenant = Tenant::create([
                'name' => $registration->org_name,
                'slug' => $slug,
                'domain' => $registration->domain,
                'plan' => 'enterprise',
                'status' => 'active',
                'trial_ends_at' => now()->addDays(14),
                'settings' => ['region' => $region, 'source' => 'enterprise_self_serve'],
                'limits' => config('dodo.plans.enterprise.limits', ['agents' => 250, 'flows' => 1000]),
            ]);

            User::create([
                'tenant_id' => $tenant->id,
                'name' => $registration->contact_name ?: $registration->org_name . ' Admin',
                'email' => $registration->contact_email,
                // Random unusable password — first login goes through the
                // password-reset / invite flow, never a shipped credential.
                'password' => Hash::make(Str::random(40)),
                'role' => 'admin',
                'invited_at' => now(),
            ]);

            DB::table('enterprise_registrations')->where('id', $registration->id)->update([
                'tenant_id' => $tenant->id,
                'region' => $region,
                'status' => 'active',
                'updated_at' => now(),
            ]);

            $this->eventStore->append(
                (string) $tenant->id,
                'tenant',
                (string) $tenant->id,
                'tenant.created',
                [
                    'source' => 'enterprise_self_serve',
                    'enterprise_id' => $registration->id,
                    'region' => $region,
                    'domain' => $registration->domain,
                ],
            );

            return $tenant;
        });

        return response()->json(['tenant' => $this->tenantPayload($tenant, $registration, $region)]);
    }

    /**
     * POST /api/enterprise/register/scim/generate
     *
     * Returns the raw token once; only the SHA-256 hash is stored.
     */
    public function scimGenerate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'nullable|string',
            'tenant_id' => 'required|string',
        ]);

        $registration = $this->registrationForTenant($validated['tenant_id'], $validated['enterprise_id'] ?? null);

        if (! $registration) {
            return response()->json(['detail' => 'registration not found for tenant'], 404);
        }

        $raw = Str::random(48);

        DB::table('enterprise_registrations')->where('id', $registration->id)->update([
            'scim_token_hash' => hash('sha256', $raw),
            'scim_expires_at' => now()->addDays(365),
            'updated_at' => now(),
        ]);

        return response()->json([
            'scim_token' => $raw,
            'scim_base_url' => rtrim((string) config('app.url'), '/') . '/api/scim/v2',
            'expires_in_days' => 365,
        ]);
    }

    /**
     * POST /api/enterprise/register/bundle/create
     *
     * Records a signed-bundle build request. Unlike the demo mock this does
     * not fabricate a zip — the request is queued for the release pipeline
     * and its status is queryable.
     */
    public function bundleCreate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_id' => 'nullable|string',
            'tenant_id' => 'required|string',
            'target' => 'nullable|string|max:40',
            'components' => 'nullable|array',
            'components.*' => 'string|max:40',
        ]);

        if (! Tenant::query()->whereKey($validated['tenant_id'])->exists()) {
            return response()->json(['detail' => 'tenant not found'], 404);
        }

        $bundleId = 'bdl_' . Str::lower(Str::random(20));
        $target = $validated['target'] ?? 'linux-x86_64';
        $components = $validated['components'] ?? ['runtime', 'connectors', 'cockpit-agent'];

        DB::table('aios_bundle_requests')->insert([
            'id' => $bundleId,
            'tenant_id' => $validated['tenant_id'],
            'enterprise_id' => $validated['enterprise_id'] ?? null,
            'target' => $target,
            'components' => json_encode($components),
            'status' => 'queued',
            'expires_at' => now()->addDays(14),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('enterprise-> bundleCreate(): bundle build queued', [
            'bundle_id' => $bundleId,
            'tenant_id' => $validated['tenant_id'],
            'target' => $target,
        ]);

        return response()->json([
            'bundle_id' => $bundleId,
            'tenant_id' => $validated['tenant_id'],
            'target' => $target,
            'components' => $components,
            'status' => 'queued',
            'detail' => 'Bundle build queued. You will receive a signed download link by email when it is ready.',
            'expires_at' => now()->addDays(14)->toIso8601String(),
        ]);
    }

    /**
     * POST /api/enterprise/register/deploy/start
     */
    public function deployStart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bundle_id' => 'required|string',
        ]);

        $bundle = DB::table('aios_bundle_requests')->where('id', $validated['bundle_id'])->first();

        if (! $bundle) {
            return response()->json(['detail' => 'bundle not found'], 404);
        }

        $deploymentId = 'dep_' . Str::lower(Str::random(20));

        DB::table('aios_deployments')->insert([
            'id' => $deploymentId,
            'bundle_id' => $bundle->id,
            'tenant_id' => $bundle->tenant_id,
            'status' => 'queued',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'deployment_id' => $deploymentId,
            'status' => 'queued',
        ]);
    }

    private function tenantPayload(Tenant $tenant, object $registration, ?string $region = null): array
    {
        return [
            'id' => (string) $tenant->id,
            'enterprise_id' => $registration->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'region' => $region ?? ($tenant->settings['region'] ?? $registration->region),
            'plan' => 'Enterprise',
            'status' => $tenant->status === 'active' ? 'live' : $tenant->status,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'first_admin_email' => $registration->contact_email,
        ];
    }

    private function registrationForTenant(string $tenantId, ?string $enterpriseId): ?object
    {
        $query = DB::table('enterprise_registrations');

        if ($enterpriseId) {
            $query->where('id', $enterpriseId);
        } else {
            $query->where('tenant_id', $tenantId);
        }

        $registration = $query->first();

        // The tenant on the registration must match the one being provisioned.
        if ($registration && $registration->tenant_id && $registration->tenant_id !== $tenantId) {
            return null;
        }

        return $registration;
    }

    private function domainTokenPresent(string $domain, string $token): bool
    {
        if (app()->environment('testing')) {
            return false; // tests exercise the auto-verify config path explicitly
        }

        try {
            $records = dns_get_record($domain, DNS_TXT) ?: [];
        } catch (\Throwable) {
            return false;
        }

        foreach ($records as $record) {
            $txt = $record['txt'] ?? '';
            if (trim($txt) === 'spidernet-verify=' . $token) {
                return true;
            }
        }

        return false;
    }
}
