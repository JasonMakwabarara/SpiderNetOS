<?php

use App\Http\Middleware\CheckAgentPermission;
use App\Http\Middleware\EnforcePlanLimits;
use App\Http\Middleware\EnforcePlanQuota;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\RequireCapability;
use App\Http\Middleware\RequirePackEntitlement;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\ThrottleBroadcast;
use App\Http\Middleware\VerifyAffonsoSignature;
use App\Http\Middleware\VerifyDodoSignature;
use App\Http\Middleware\VerifyInternalKey;
use App\Http\Middleware\VerifyTwilioSignature;
use App\Http\Middleware\VoiceFeatureFlag;
use App\Providers\OutreachServiceProvider;
use App\Providers\SecurityServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        SecurityServiceProvider::class,
        OutreachServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Global — applied to every HTTP response. Security headers must run
        // last so they overlay on top of any framework-set headers.
        $middleware->append(SecurityHeadersMiddleware::class);

        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        // Public, unauthenticated browser endpoints. Sanctum's stateful
        // pipeline (above) runs the CSRF check on any request whose
        // Origin/Referer matches a stateful domain — but neither SPA ever
        // calls /sanctum/csrf-cookie, so the FIRST browser POST of a fresh
        // session 419s (visitors could not register or sign in). These
        // routes grant no cookie-session privileges — credentials travel in
        // the body and auth comes back as a Bearer token — so CSRF adds no
        // protection here.
        $middleware->validateCsrfTokens(except: [
            'api/auth/login',
            'api/auth/register',
            'api/enterprise/register/*',
            'api/enterprise/auth/*',
            'api/public/lead-capture/*',
            // One-click unsubscribe (RFC 8058) is POSTed by mail clients, never
            // by a browser session; token-gated, grants nothing.
            'api/public/outreach/*',
        ]);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'agent.permission' => CheckAgentPermission::class,
            'cost.limit' => EnforcePlanLimits::class,
            'throttle.broadcast' => ThrottleBroadcast::class,
            // Voice AI — Phase A
            'voice.verify_twilio' => VerifyTwilioSignature::class,
            'voice.feature_flag' => VoiceFeatureFlag::class,
            // Role-split frontend
            'role' => RequireRole::class,
            'can.do' => RequireCapability::class,
            'step.up' => RequireStepUp::class,
            // Onboarding (Phase 1)
            'onboarding.required' => EnsureOnboardingComplete::class,
            // Backend-internal routes called by Python intelligence workers
            'internal.key' => VerifyInternalKey::class,
            // Dodo Payments webhooks
            'dodo.verify_signature' => VerifyDodoSignature::class,
            // Affonso affiliate webhooks (per-tenant signing secret)
            'affonso.verify_signature' => VerifyAffonsoSignature::class,
            // Feature-pack runtime entitlement gate (pack.entitled:{pack_id})
            'pack.entitled' => RequirePackEntitlement::class,
            // Plan countable-quota gate on create routes (plan.quota:agents|flows|seats)
            'plan.quota' => EnforcePlanQuota::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
