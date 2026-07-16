<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * SecurityServiceProvider
 *
 * Registers the Tier 1 rate-limit tiers consumed by route-level
 * `throttle:<name>` middleware. Limits are sourced from config/security.php
 * so ops can tune per-environment via env.
 *
 * The resolver for each limiter keys on authenticated user id when
 * available, falling back to the client IP otherwise. This prevents a
 * single user from getting throttled by their NAT peer.
 */
class SecurityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $tiers = (array) config('security.rate_limits', []);

        // ── Authentication (login / register / step-up) ─────────────────
        // IP-only keying to prevent credential-stuffing from a single host.
        RateLimiter::for('auth', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['auth'] ?? 5))
                ->by($request->ip())
                ->response(fn () => response()->json([
                    'error' => 'rate_limited',
                    'retry_after_seconds' => 60,
                ], 429));
        });

        // ── General authenticated API ──────────────────────────────────
        RateLimiter::for('api', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['api'] ?? 60))
                ->by(optional($request->user())->id ?: $request->ip());
        });

        // ── Admin surface (user CRUD, audit, copy state) ───────────────
        RateLimiter::for('admin', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['admin'] ?? 30))
                ->by(optional($request->user())->id ?: $request->ip());
        });

        // ── Platform super-admin surface (feature flags, impersonation) ─
        RateLimiter::for('platform', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['platform'] ?? 20))
                ->by(optional($request->user())->id ?: $request->ip());
        });

        // ── Atlas chat / command (LLM-backed, expensive) ───────────────
        RateLimiter::for('atlas_chat', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['atlas_chat'] ?? 30))
                ->by(optional($request->user())->id ?: $request->ip());
        });

        // ── Voice webhooks (already signature-verified, generous cap) ──
        RateLimiter::for('voice_webhook', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['voice_webhook'] ?? 600))
                ->by($request->ip());
        });

        // ── Public lead capture (unauthenticated, embedded on tenant sites) ─
        RateLimiter::for('lead_capture', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['lead_capture'] ?? 20))
                ->by($request->ip());
        });

        // ── Payment provider webhooks (already signature-verified) ─────
        RateLimiter::for('payment_webhook', function (Request $request) use ($tiers) {
            return Limit::perMinute((int) ($tiers['payment_webhook'] ?? 300))
                ->by($request->ip());
        });
    }
}
