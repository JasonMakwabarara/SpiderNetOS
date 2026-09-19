<?php

declare(strict_types=1);

namespace App\Http\Controllers\Enterprise;

use App\Http\Controllers\Controller;
use App\Mail\BrandedMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\MfaService;
use App\Services\EventStore;
use App\Services\UnifiedAuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Enterprise sign-in methods the marketing-site SignInPage calls: TOTP,
 * magic link, SSO, WebAuthn. Implements the contract the SPA was built
 * against (previously only in the FastAPI mock, backend/enterprise_api.py)
 * — but never its demo bypasses: every session is issued for an EXISTING
 * user via UnifiedAuthSession, and all demo behavior (demo SSO IdP,
 * WebAuthn stub, TOTP 000000, dev_link in responses) is gated by
 * config('enterprise.demo_auth_enabled'), which defaults OFF in production.
 *
 * Error contract: the SPA reads only `detail` from error bodies — every
 * domain error here is `{"detail": "..."}`.
 */
class EnterpriseAuthController extends Controller
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly MfaService $mfa,
    ) {}

    /**
     * POST /api/enterprise/auth/totp/login
     *
     * Real sign-in for users who enrolled TOTP (totp_confirmed_at set).
     * Deliberately a single factor — the sign-in page offers it as a
     * first-class method with no password field; see CHANGELOG 2026-08-06.
     */
    public function totpLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:190',
            // String + regex preserves leading zeros ("012345").
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $email = strtolower(trim($validated['email']));
        $code = $validated['code'];
        $limiterKey = 'totp:'.sha1($email);

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json(['detail' => 'Too many attempts. Try again in a minute.'], 429);
        }

        $users = $this->usersByEmail($email);

        // Demo bypass — gated, and only for existing users.
        if ($this->demoEnabled() && $code === '000000' && $users->isNotEmpty()) {
            $user = $users->first();

            return $this->tenantBlocked($user) ?? $this->issueSession($user, 'totp_demo');
        }

        if (! config('enterprise.totp_login_enabled')) {
            return response()->json(['detail' => 'TOTP sign-in is not enabled.'], 400);
        }

        // Emails are unique per-tenant, not globally: verify the code against
        // every enrolled account with this email — a valid code proves
        // possession of that specific account's secret.
        foreach ($users as $user) {
            if (! $user->hasMfaEnrolled()) {
                continue;
            }
            if ($this->mfa->verify((string) $user->totp_secret, $code)) {
                RateLimiter::clear($limiterKey);

                return $this->tenantBlocked($user) ?? $this->issueSession($user, 'totp');
            }
        }

        RateLimiter::hit($limiterKey, 60);

        // One generic string for unknown email / not enrolled / wrong code.
        return response()->json(['detail' => 'invalid TOTP code'], 401);
    }

    /**
     * POST /api/enterprise/auth/magic-link/request
     */
    public function magicLinkRequest(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:190']);
        $email = strtolower(trim($validated['email']));

        // Anti email-bombing. Throttling by email leaks nothing — it fires
        // for any address, known or not.
        $limiterKey = 'magiclink:'.sha1($email);
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            return response()->json(['detail' => 'Too many magic link requests. Try again later.'], 429);
        }
        RateLimiter::hit($limiterKey, 900);

        // Unknown email: same success response, mint nothing (anti-enumeration).
        $user = $this->usersByEmail($email)->first();
        if ($user === null) {
            return response()->json(['sent' => true]);
        }

        $expiryMinutes = (int) config('enterprise.magic_link_expiry_minutes', 30);
        $raw = Str::random(48);

        DB::table('magic_links')->insert([
            'id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $raw),
            'user_id' => $user->id,
            'email' => $email,
            'expires_at' => now()->addMinutes($expiryMinutes),
            'requested_ip' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $url = config('enterprise.signin_url').'?magic_token='.$raw;
        $body = "Use this link to sign in to SpiderNetOS:\n\n{$url}\n\n"
            ."It expires in {$expiryMinutes} minutes and can only be used once. "
            ."If you didn't request it, you can ignore this email.";

        try {
            Mail::to($user->email)->send(
                new BrandedMail($user->tenant, 'Your SpiderNetOS sign-in link', $body),
            );
        } catch (\Throwable $e) {
            // A mailer outage must not become an email-enumeration oracle.
            Log::error('[EnterpriseAuth] magic link mail failed', ['error' => $e->getMessage()]);
        }

        $this->eventStore->append(
            tenantId: $user->tenant_id,
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            eventType: 'user.magic_link.requested',
            payload: ['ip' => $request->ip()],
        );

        $response = ['sent' => true];
        if ($this->demoEnabled()) {
            // SignInPage renders dev_link.token.slice(0,32) — must be a string.
            $response['dev_link'] = ['token' => $raw, 'expires_in' => $expiryMinutes * 60];
        }

        return response()->json($response);
    }

    /**
     * POST /api/enterprise/auth/magic-link/verify
     *
     * Detail strings and check order (expired before used) preserve the
     * contract the SPA and the mock's tests were built against.
     */
    public function magicLinkVerify(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => 'required|string|min:32|max:128']);

        $row = DB::table('magic_links')
            ->where('token_hash', hash('sha256', $validated['token']))
            ->first();

        if ($row === null) {
            return response()->json(['detail' => 'invalid magic link'], 400);
        }
        if (now()->greaterThan($row->expires_at)) {
            return response()->json(['detail' => 'magic link expired'], 400);
        }
        if ($row->used_at !== null) {
            return response()->json(['detail' => 'magic link already used or invalid'], 400);
        }

        // Atomic single-use flip — a concurrent verify loses the race cleanly.
        $flipped = DB::table('magic_links')
            ->where('id', $row->id)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);
        if ($flipped === 0) {
            return response()->json(['detail' => 'magic link already used or invalid'], 400);
        }

        $user = User::find($row->user_id);
        if ($user === null) {
            return response()->json(['detail' => 'invalid magic link'], 400);
        }
        if ($blocked = $this->tenantBlocked($user)) {
            return $blocked;
        }

        // The inbox is proven — also serves as activation for registration-
        // created admins who never set a password.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->issueSession($user, 'magic_link');
    }

    /**
     * POST /api/enterprise/auth/sso/start
     */
    public function ssoStart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => 'nullable|string|max:64',
            'provider' => 'required|string|max:40',
        ]);

        $provider = strtolower($validated['provider']);

        if (str_contains($provider, 'demo')) {
            if (! $this->demoEnabled()) {
                return response()->json(['detail' => 'Demo SSO is not enabled in this environment.'], 400);
            }

            $slug = $validated['tenant_slug'] ?: 'demo';
            $tenant = Tenant::where('slug', $slug)->first();
            if ($tenant === null) {
                return response()->json(['detail' => 'tenant not found'], 404);
            }

            // Highest-role user, oldest first on ties.
            $user = $tenant->users()->get()
                ->sortBy([['created_at', 'asc']])
                ->sortByDesc(fn (User $u) => $u->roleRank())
                ->first();
            if ($user === null) {
                return response()->json(['detail' => 'no users found for tenant'], 404);
            }

            return $this->tenantBlocked($user)
                ?? $this->issueSession($user, 'sso_demo', ['completed' => true]);
        }

        // Real IdPs need config + an OIDC/SAML client that don't exist yet.
        // No fake authorization_url — the SPA would hard-navigate to it.
        return response()->json([
            'detail' => "SSO for {$validated['provider']} requires IdP configuration in Cockpit → Security — not yet available.",
        ], 400);
    }

    /**
     * GET /api/enterprise/auth/sso/callback
     *
     * Published to customers as the SP redirect URI, so the route must exist —
     * but until real IdP flows land it only bounces back to the sign-in page.
     * Never JSON (a top-level browser navigation lands here), never a session
     * (the mock issued one from a replayable stored state — an auth bypass).
     */
    public function ssoCallback(Request $request): RedirectResponse
    {
        Log::info('[EnterpriseAuth] sso.callback hit', [
            'state' => (string) $request->query('state', ''),
            'ip' => $request->ip(),
        ]);

        $message = 'SSO sign-in is not yet available. Ask your admin to configure your identity provider.';

        return redirect()->away(config('enterprise.signin_url').'?sso_error='.urlencode($message));
    }

    /**
     * POST /api/enterprise/auth/webauthn/login
     *
     * The SPA never sends an assertion (stub UI) — without a real ceremony
     * this can only ever be a demo flow.
     */
    public function webauthnLogin(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email|max:190']);

        if (! $this->demoEnabled()) {
            return response()->json(['detail' => 'WebAuthn sign-in is not yet available.'], 400);
        }

        $user = $this->usersByEmail(strtolower(trim($validated['email'])))->first();
        if ($user === null) {
            return response()->json(['detail' => 'Passkey not registered for this user.'], 401);
        }

        return $this->tenantBlocked($user) ?? $this->issueSession($user, 'webauthn_demo');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function demoEnabled(): bool
    {
        return (bool) config('enterprise.demo_auth_enabled');
    }

    /** @return Collection<int, User> oldest account first */
    private function usersByEmail(string $email): Collection
    {
        return User::where('email', $email)->orderBy('created_at')->get();
    }

    /** 403 when the user's tenant is suspended/cancelled, null otherwise. */
    private function tenantBlocked(User $user): ?JsonResponse
    {
        if (in_array($user->tenant?->status, ['suspended', 'cancelled'], true)) {
            return response()->json(['detail' => 'This workspace is suspended. Contact support.'], 403);
        }

        return null;
    }

    /**
     * Mirrors AuthController::login: last_login_at touch, user.logged_in
     * event, Sanctum token, UnifiedAuthSession envelope (+ extras such as
     * SSO's `completed: true`).
     */
    private function issueSession(User $user, string $method, array $extra = []): JsonResponse
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $this->eventStore->append(
            tenantId: $user->tenant_id,
            aggregateType: 'user',
            aggregateId: (string) $user->id,
            eventType: 'user.logged_in',
            payload: [
                'method' => $method,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        );

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json(array_merge(UnifiedAuthSession::format($user, $token), $extra));
    }
}
