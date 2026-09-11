<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        \App\Providers\SecurityServiceProvider::class,
        \App\Providers\OutreachServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Global — applied to every HTTP response. Security headers must run
        // last so they overlay on top of any framework-set headers.
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
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
            'tenant'            => \App\Http\Middleware\ResolveTenant::class,
            'agent.permission'  => \App\Http\Middleware\CheckAgentPermission::class,
            'cost.limit'        => \App\Http\Middleware\EnforcePlanLimits::class,
            'throttle.broadcast'=> \App\Http\Middleware\ThrottleBroadcast::class,
            // Voice AI — Phase A
            'voice.verify_twilio' => \App\Http\Middleware\VerifyTwilioSignature::class,
            'voice.feature_flag'  => \App\Http\Middleware\VoiceFeatureFlag::class,
            // Role-split frontend
            'role'              => \App\Http\Middleware\RequireRole::class,
            'can.do'            => \App\Http\Middleware\RequireCapability::class,
            'step.up'           => \App\Http\Middleware\RequireStepUp::class,
            // Onboarding (Phase 1)
            'onboarding.required' => \App\Http\Middleware\EnsureOnboardingComplete::class,
            // Backend-internal routes called by Python intelligence workers
            'internal.key'      => \App\Http\Middleware\VerifyInternalKey::class,
            // Dodo Payments webhooks
            'dodo.verify_signature' => \App\Http\Middleware\VerifyDodoSignature::class,
            // Feature-pack runtime entitlement gate (pack.entitled:{pack_id})
            'pack.entitled'     => \App\Http\Middleware\RequirePackEntitlement::class,
            // Plan countable-quota gate on create routes (plan.quota:agents|flows|seats)
            'plan.quota'        => \App\Http\Middleware\EnforcePlanQuota::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
