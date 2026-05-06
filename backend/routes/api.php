<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\AtlasController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\BriefController;
use App\Http\Controllers\ObservabilityController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\VoiceStreamController;
use App\Http\Controllers\Integrations\IntegrationsController;
use App\Http\Controllers\Integrations\CalendarController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\FeaturePackController;

/*
|--------------------------------------------------------------------------
| SpiderNet OS v3.2 — API Routes
|--------------------------------------------------------------------------
| All routes are event-sourced through the EventStore service.
| CostGovernor middleware enforces budget constraints.
*/

// Health check (no auth required)
Route::get('/health', [HealthController::class, 'index']);

// Voice AI — Telephony webhooks (no auth, Twilio-signed — Phase A hardened)
// High throttle cap; signature verification is the real gate.
Route::prefix('voice')->middleware(['throttle:voice_webhook', 'voice.verify_twilio', 'voice.feature_flag'])->group(function () {
    Route::post('/inbound',   [VoiceController::class, 'inbound']);
    Route::post('/gather',    [VoiceController::class, 'gather']);
    Route::post('/status',    [VoiceController::class, 'status'])->withoutMiddleware(['voice.feature_flag']);
    Route::post('/recording', [VoiceController::class, 'recording'])->withoutMiddleware(['voice.feature_flag']);
});

// Voice AI — WebSocket streaming (Phase C) — Twilio-signed only, no feature flag needed at transport layer
Route::post('/voice/stream/connect', [VoiceStreamController::class, 'connect'])
    ->middleware('voice.verify_twilio');

// Authentication — Tier 1 rate-limited (IP-keyed to resist credential-stuffing).
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    // Role-split frontend: MFA/password re-verify for sensitive ops
    Route::post('/step-up', [AuthController::class, 'stepUp'])->middleware('auth:sanctum');
});

// Protected routes (require authentication + tenant resolution + onboarding complete + global api throttle)
// Note: onboarding routes exempt via routeIs() in EnsureOnboardingComplete middleware
Route::middleware(['auth:sanctum', 'tenant', 'onboarding.required', 'cost.limit', 'throttle:api'])->group(function () {
    
    // Command System
    Route::post('/command', [CommandController::class, 'execute']);
    Route::post('/command/enhance', [CommandController::class, 'enhancePrompt']);
    
    // Atlas UI Interface — LLM-backed, secondary throttle to cap $/min per user.
    Route::middleware('throttle:atlas_chat')->group(function () {
        Route::post('/atlas/chat', [AtlasController::class, 'chat']);
        Route::post('/atlas/plan', [AtlasController::class, 'plan']);
        Route::post('/atlas/execute', [AtlasController::class, 'executePlan']);
        Route::post('/atlas/cancel', [AtlasController::class, 'cancelPlan']);
    });

    // Atlas behavioral event ingestion (B5) — lightweight, non-blocking.
    Route::post('/atlas/events', [AtlasController::class, 'events']);

    // Enhance Prompt — transforms a terse prompt into a structured instruction.
    // Gated by feature flag `atlas.enhance_prompt`.
    Route::post('/atlas/enhance-prompt', [AtlasController::class, 'enhancePrompt']);
    
    // Flows (DAG execution)
    Route::apiResource('flows', FlowController::class);
    Route::post('/flows/{flow}/execute', [FlowController::class, 'execute']);
    Route::post('/flows/{flow}/publish', [FlowController::class, 'publish']);
    Route::get('/flows/{flow}/executions', [FlowController::class, 'executions']);
    
    // Flow Executions
    Route::get('/executions/{execution}', [FlowController::class, 'executionStatus']);
    
    // Agents — CRUD + management
    Route::get('/agents', [AgentController::class, 'index']);
    Route::post('/agents', [AgentController::class, 'store']);
    Route::get('/agents/templates', [AgentController::class, 'templates']);
    Route::get('/agents/graph/delegation', [AgentController::class, 'delegationGraph']);
    Route::get('/agents/{agent}', [AgentController::class, 'show']);
    Route::put('/agents/{agent}', [AgentController::class, 'update']);
    Route::delete('/agents/{agent}', [AgentController::class, 'destroy']);
    Route::patch('/agents/{agent}/status', [AgentController::class, 'toggleStatus']);
    Route::post('/agents/{agent}/dispatch', [AgentController::class, 'dispatch']);
    Route::post('/agents/{agent}/test', [AgentController::class, 'test']);
    Route::post('/agents/{agent}/activate', [AgentController::class, 'activate']);
    Route::get('/agents/{agent}/sessions', [AgentController::class, 'sessions']);
    Route::get('/agents/{agent}/capabilities', [AgentController::class, 'capabilities']);
    
    // Approvals (Human-in-the-loop gates)
    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);
    
    // Daily Brief
    Route::get('/brief/latest', [BriefController::class, 'latest']);
    Route::get('/brief/history', [BriefController::class, 'history']);
    
    // Observability (Trace replay)
    Route::get('/traces', [ObservabilityController::class, 'index']);
    Route::get('/traces/{dag_id}', [ObservabilityController::class, 'trace']);
    Route::get('/traces/{dag_id}/replay', [ObservabilityController::class, 'replay']);
    Route::get('/traces/{dag_id}/divergence', [ObservabilityController::class, 'divergence']);
    
    // Usage & Budget
    Route::get('/usage/budget', [ObservabilityController::class, 'budget']);
    Route::put('/usage/budget', [ObservabilityController::class, 'updateBudget']);
    Route::get('/usage/current', [ObservabilityController::class, 'currentUsage']);
    Route::get('/usage/daily', [ObservabilityController::class, 'dailyUsage']);
    Route::get('/usage/monthly', [ObservabilityController::class, 'monthlyUsage']);
    // Shadow deploy gate (SRE / admin)
    Route::get('/usage/shadow/gate', [ObservabilityController::class, 'shadowGateStatus']);

    // Atlas Copy API (§11.4)
    Route::get('/atlas/copy', [\App\Http\Controllers\AtlasCopyController::class, 'serve']);
    Route::post('/atlas/copy/{variantId}/event', [\App\Http\Controllers\AtlasCopyController::class, 'recordEvent']);

    // Voice AI — Management (authenticated)
    Route::post('/voice/call',              [VoiceController::class, 'initiateCall']);
    Route::get('/voice/calls',              [VoiceController::class, 'listCalls']);
    // Phase D additions
    Route::get('/voice/calls/{id}',         [VoiceController::class, 'showCall']);
    Route::get('/voice/calls/{id}/transcript.txt', [VoiceController::class, 'exportTranscript']);
    Route::get('/voice/numbers',            [VoiceController::class, 'listNumbers']);
    Route::post('/voice/numbers',           [VoiceController::class, 'createNumber']);
    Route::patch('/voice/numbers/{id}',     [VoiceController::class, 'updateNumber']);
    Route::delete('/voice/numbers/{id}',    [VoiceController::class, 'deleteNumber']);
    Route::get('/voice/quotas',             [VoiceController::class, 'getQuotas']);
    Route::put('/voice/quotas',             [VoiceController::class, 'updateQuotas']);
    // Integrations (Phase D)
    Route::get('/integrations',                       [IntegrationsController::class, 'index']);
    Route::post('/integrations/{provider}/authorize', [IntegrationsController::class, 'authorize']);
    Route::post('/integrations/calendar/book',        [CalendarController::class, 'book']);

    // Feature Packs (Phase 3)
    Route::get('/feature-packs', [FeaturePackController::class, 'index']);
    Route::get('/feature-packs/{id}', [FeaturePackController::class, 'show']);
});

// ─── Admin workspace (role:admin) ───────────────────────────────
Route::middleware(['auth:sanctum', 'tenant', 'role:admin', 'throttle:admin'])->prefix('admin')->group(function () {
        // Users
        Route::get('/users', [AdminController::class, 'listUsers']);
        // Both invite and standard POST alias
        Route::post('/users',         [AdminController::class, 'inviteUser'])->middleware('step.up');
        Route::post('/users:invite',  [AdminController::class, 'inviteUser'])->middleware('step.up');
        Route::patch('/users/{id}',   [AdminController::class, 'updateUser'])->middleware('step.up');
        Route::delete('/users/{id}',  [AdminController::class, 'deleteUser'])->middleware('step.up');

        // Audit log
        Route::get('/audit', [AdminController::class, 'audit']);

        // Atlas copy state
        Route::get('/copy/state', [AdminController::class, 'getCopyState']);
        Route::put('/copy/state', [AdminController::class, 'putCopyState'])->middleware('step.up');

        // Onboarding (Phase 1) — exempt from onboarding.required gate
        Route::get('/onboarding', [OnboardingController::class, 'show']);
        Route::put('/onboarding', [OnboardingController::class, 'update']);
        Route::post('/onboarding/observe', [OnboardingController::class, 'observe']);
        Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);

        // Automation Level settings (available after onboarding too)
        Route::put('/tenant/automation-level', [OnboardingController::class, 'updateAutomationLevel']);
    });

// ─── Platform workspace (role:super_admin) ──────────────────────
Route::middleware(['auth:sanctum', 'role:super_admin', 'throttle:platform'])->prefix('platform')->group(function () {
        Route::get('/overview', [PlatformController::class, 'overview']);

        // Feature flags
        Route::get('/feature-flags',              [PlatformController::class, 'listFlags']);
        Route::get('/feature-flags/{name}',       [PlatformController::class, 'getFlag']);
        Route::put('/feature-flags/{name}',       [PlatformController::class, 'putFlag'])->middleware('step.up');
        Route::delete('/feature-flags/{name}',    [PlatformController::class, 'deleteFlag'])->middleware('step.up');

        // Impersonation
        Route::post('/impersonate',              [PlatformController::class, 'impersonate'])->middleware('step.up');
        Route::post('/impersonate/{id}/end',     [PlatformController::class, 'endImpersonation']);
    });

// ─── State Transition Engine (STE) — read-first, super_admin only ──────────
Route::middleware(['auth:sanctum', 'role:super_admin', 'can.do:ste.view'])
    ->prefix('ste')
    ->group(function () {
        Route::get('/matrix',             [\App\Http\Controllers\StateEngineController::class, 'matrix']);
        Route::get('/matrix/conditional', [\App\Http\Controllers\StateEngineController::class, 'conditionalMatrix']);
        Route::get('/dropoffs',           [\App\Http\Controllers\StateEngineController::class, 'dropoffs']);
        Route::get('/winning-tags',       [\App\Http\Controllers\StateEngineController::class, 'winningTags']);
        Route::get('/unmapped',           [\App\Http\Controllers\StateEngineController::class, 'unmapped']);
        Route::get('/lag',                [\App\Http\Controllers\StateEngineController::class, 'lag']);
        Route::post('/simulate',          [\App\Http\Controllers\StateEngineController::class, 'simulate'])
            ->middleware('can.do:ste.simulate');
    });
