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
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Global — applied to every HTTP response. Security headers must run
        // last so they overlay on top of any framework-set headers.
        $middleware->append(\App\Http\Middleware\SecurityHeadersMiddleware::class);

        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
