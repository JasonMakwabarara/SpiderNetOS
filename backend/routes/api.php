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
use App\Http\Controllers\IntelligenceProxyController;
use App\Http\Controllers\OutcomesController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\ShareLinkController;

/*
|--------------------------------------------------------------------------
| SpiderNet OS v3.2 — API Routes
|--------------------------------------------------------------------------
| All routes are event-sourced through the EventStore service.
| CostGovernor middleware enforces budget constraints.
*/

// Health check (no auth required)
Route::get('/health', [HealthController::class, 'index']);

// Public, token-gated read-only share links.
Route::get('/public/traces/{token}', [ShareLinkController::class, 'publicTrace']);
Route::get('/public/approvals/{token}', [ShareLinkController::class, 'publicApproval']);

// Public lead capture — embedded on the tenant's own external site/landing
// page, so it is intentionally unauthenticated. {tenant} is a tenant UUID,
// not a secret (same trust model as e.g. a public form/portal id).
Route::post('/public/lead-capture/{tenant}', [\App\Http\Controllers\Sales\PublicLeadController::class, 'store'])
    ->middleware('throttle:lead_capture');

// Partner outreach unsubscribe (token-gated; GET confirms, POST acts — also
// the RFC 8058 one-click target advertised in List-Unsubscribe).
Route::get('/public/outreach/unsubscribe/{token}', [\App\Http\Controllers\Sales\PublicOutreachController::class, 'confirm'])
    ->middleware('throttle:lead_capture');
Route::post('/public/outreach/unsubscribe/{token}', [\App\Http\Controllers\Sales\PublicOutreachController::class, 'unsubscribe'])
    ->middleware('throttle:lead_capture');

// V2 intelligence layer — proxied through Laravel (Sanctum required except health)
Route::prefix('v2/intelligence')->group(function () {
    Route::get('/health', [IntelligenceProxyController::class, 'health']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/evaluate', [IntelligenceProxyController::class, 'evaluate']);
        Route::post('/atlas/coordinate-cycle', [IntelligenceProxyController::class, 'coordinateCycle']);
        Route::post('/compile', [IntelligenceProxyController::class, 'compileDag']);
    });
});

// Voice AI — Telephony webhooks (no auth, Twilio-signed — Phase A hardened)
// High throttle cap; signature verification is the real gate.
Route::prefix('voice')->middleware(['throttle:voice_webhook', 'voice.verify_twilio', 'voice.feature_flag'])->group(function () {
    Route::post('/inbound',   [VoiceController::class, 'inbound']);
    Route::post('/gather',    [VoiceController::class, 'gather']);
    Route::post('/status',    [VoiceController::class, 'status'])->withoutMiddleware(['voice.feature_flag']);
    Route::post('/recording', [VoiceController::class, 'recording'])->withoutMiddleware(['voice.feature_flag']);
});

// WhatsApp — Twilio Messages webhooks (no auth, Twilio-signed). Reuses
// voice.verify_twilio: the Twilio HMAC-SHA1 signature scheme is identical
// across products (URL + sorted POST params), not voice-specific.
Route::prefix('whatsapp')->middleware(['throttle:voice_webhook', 'voice.verify_twilio'])->group(function () {
    Route::post('/inbound', [\App\Http\Controllers\WhatsAppController::class, 'inbound']);
    Route::post('/status', [\App\Http\Controllers\WhatsAppController::class, 'status']);
});

// Dodo Payments — purchase webhooks (no auth, signature-verified)
// Affonso affiliate webhooks (no auth; X-Affonso-Signature verified per tenant)
Route::post('/webhooks/affonso/{tenant}', [\App\Http\Controllers\Webhooks\AffonsoWebhookController::class, 'handle'])
    ->middleware(['affonso.verify_signature', 'throttle:payment_webhook']);

Route::post('/webhooks/dodo', [\App\Http\Controllers\Webhooks\DodoWebhookController::class, 'handle'])
    ->middleware(['throttle:payment_webhook', 'dodo.verify_signature']);

// Enterprise self-serve registration funnel (public, heavily throttled,
// kill-switched via config('enterprise.self_serve_enabled')). Restored from
// real trunk e31d996 — prod serves this today.
Route::prefix('enterprise/register')->middleware('throttle:enterprise_register')->group(function () {
    $controller = \App\Http\Controllers\Enterprise\EnterpriseRegistrationController::class;

    Route::post('/start', [$controller, 'start']);
    Route::post('/verify-domain', [$controller, 'verifyDomain']);
    Route::post('/create-tenant', [$controller, 'createTenant']);
    Route::post('/scim/generate', [$controller, 'scimGenerate']);
    Route::post('/bundle/create', [$controller, 'bundleCreate']);
    Route::post('/deploy/start', [$controller, 'deployStart']);
});

// Enterprise sign-in methods the marketing-site SignInPage calls (public,
// Tier-1 throttled, CSRF-exempted in bootstrap/app.php). Demo behavior is
// gated by config('enterprise.demo_auth_enabled') — OFF in production.
Route::prefix('enterprise/auth')->middleware('throttle:auth')->group(function () {
    $controller = \App\Http\Controllers\Enterprise\EnterpriseAuthController::class;

    Route::post('/totp/login', [$controller, 'totpLogin']);
    Route::post('/magic-link/request', [$controller, 'magicLinkRequest']);
    Route::post('/magic-link/verify', [$controller, 'magicLinkVerify']);
    Route::post('/sso/start', [$controller, 'ssoStart']);
    Route::get('/sso/callback', [$controller, 'ssoCallback']);
    Route::post('/webauthn/login', [$controller, 'webauthnLogin']);
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

    // TOTP MFA enrollment + management
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/mfa/enroll', [AuthController::class, 'mfaEnroll']);
        Route::post('/mfa/confirm', [AuthController::class, 'mfaConfirm']);
        Route::post('/mfa/disable', [AuthController::class, 'mfaDisable'])->middleware('step.up');
        Route::post('/mfa/recovery-codes', [AuthController::class, 'mfaRecoveryCodes'])->middleware('step.up');
    });
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
        Route::post('/atlas/confirm', [AtlasController::class, 'confirm']);
        Route::post('/atlas/plan', [AtlasController::class, 'plan']);
        Route::post('/atlas/execute', [AtlasController::class, 'executePlan']);
        Route::post('/atlas/cancel', [AtlasController::class, 'cancelPlan']);
    });

    // Atlas behavioral event ingestion (B5) — lightweight, non-blocking.
    Route::post('/atlas/events', [AtlasController::class, 'events']);

    // Enhance Prompt — transforms a terse prompt into a structured instruction.
    // Gated by feature flag `atlas.enhance_prompt`.
    Route::post('/atlas/enhance-prompt', [AtlasController::class, 'enhancePrompt']);
    
    // Flows (DAG execution). Gate creation on the plan's flow quota; the
    // store action is split out of the resource so the quota middleware
    // sits only on create, never on reads.
    Route::apiResource('flows', FlowController::class)->except(['store']);
    Route::post('/flows', [FlowController::class, 'store'])->middleware('plan.quota:flows')->name('flows.store');
    Route::post('/flows/quick-create', [FlowController::class, 'quickCreate'])->middleware('plan.quota:flows');
    Route::post('/flows/{flow}/execute', [FlowController::class, 'execute']);
    Route::post('/flows/{flow}/publish', [FlowController::class, 'publish']);
    Route::get('/flows/{flow}/executions', [FlowController::class, 'executions']);
    
    // Flow Executions
    Route::get('/executions/{execution}', [FlowController::class, 'executionStatus']);
    
    // Agents — CRUD + management
    Route::get('/agents', [AgentController::class, 'index']);
    Route::post('/agents', [AgentController::class, 'store'])->middleware('plan.quota:agents');
    Route::get('/agents/templates', [AgentController::class, 'templates']);
    Route::get('/agents/graph/delegation', [AgentController::class, 'delegationGraph']);
    Route::get('/agents/{agent}/delegations', [AgentController::class, 'delegations']);
    // `breaker` is the circuit-breaker endpoint (routes/api/founder.php), not an agent id.
    Route::get('/agents/{agent}', [AgentController::class, 'show'])->where('agent', '^(?!breaker$).+');
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
    // Approval chain policies — literal segment before /{approval}
    Route::get('/approvals/policies', [\App\Http\Controllers\ApprovalPolicyController::class, 'index']);
    Route::post('/approvals/policies', [\App\Http\Controllers\ApprovalPolicyController::class, 'store'])->middleware('role:admin');
    Route::put('/approvals/policies/{id}', [\App\Http\Controllers\ApprovalPolicyController::class, 'update'])->middleware('role:admin');
    Route::delete('/approvals/policies/{id}', [\App\Http\Controllers\ApprovalPolicyController::class, 'destroy'])->middleware('role:admin');
    Route::get('/approvals/{approval}', [ApprovalController::class, 'show']);
    Route::post('/approvals/{approval}/approve', [ApprovalController::class, 'approve']);
    Route::post('/approvals/{approval}/reject', [ApprovalController::class, 'reject']);
    Route::post('/approvals/{approval}/delegate', [ApprovalController::class, 'delegate']);
    Route::post('/approvals/{id}/share', [ShareLinkController::class, 'mintApproval']);
    
    // Daily Brief
    Route::get('/brief/latest', [BriefController::class, 'latest']);
    Route::get('/brief/history', [BriefController::class, 'history']);
    
    // Observability (Trace replay)
    Route::get('/traces', [ObservabilityController::class, 'index']);
    Route::get('/traces/{dag_id}', [ObservabilityController::class, 'trace']);
    Route::get('/traces/{dag_id}/replay', [ObservabilityController::class, 'replay']);
    Route::get('/traces/{dag_id}/divergence', [ObservabilityController::class, 'divergence']);
    Route::post('/traces/{id}/share', [ShareLinkController::class, 'mintTrace']);
    
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
    Route::get('/integrations/catalogue',             [IntegrationsController::class, 'catalogue']);
    Route::post('/integrations/{provider}/authorize', [IntegrationsController::class, 'authorize']);
    Route::post('/integrations/{provider}/test',      [IntegrationsController::class, 'test']);
    Route::post('/integrations/{provider}/actions/{action}', [IntegrationsController::class, 'execute']);
    Route::delete('/integrations/{provider}',         [IntegrationsController::class, 'destroy']);
    Route::post('/integrations/calendar/book',        [CalendarController::class, 'book']);

    // Feature Packs (Phase 3)
    Route::get('/feature-packs/catalogue', [FeaturePackController::class, 'catalogue']);
    Route::get('/feature-packs/recommendations', [FeaturePackController::class, 'recommendations']);
    Route::post('/feature-packs/signals', [FeaturePackController::class, 'recordSignal']);
    Route::post('/feature-packs/feedback', [FeaturePackController::class, 'feedback']);
    Route::get('/feature-packs', [FeaturePackController::class, 'index']);
    Route::get('/feature-packs/entitlements', [FeaturePackController::class, 'entitlements']);
    Route::post('/feature-packs/{id}/install', [FeaturePackController::class, 'install']);
    Route::post('/feature-packs/{id}/checkout', [FeaturePackController::class, 'checkout']);
    Route::delete('/feature-packs/{id}', [FeaturePackController::class, 'uninstall']);
    Route::get('/feature-packs/{id}', [FeaturePackController::class, 'show']);

    // Business profile (Atlas discovery learning loop)
    Route::get('/business-profile', [BusinessProfileController::class, 'show']);
    Route::put('/business-profile', [BusinessProfileController::class, 'update']);

    // Business Systemization pack — systems map, ownership, snowball, SOPs
    // (restored from real trunk a9a2d8d/d0a5e0e — prod serves this today)
    Route::prefix('systemization')->group(function () {
        $controller = \App\Http\Controllers\SystemizationController::class;

        Route::post('/bootstrap', [$controller, 'bootstrap']);
        Route::get('/map', [$controller, 'map']);
        Route::post('/systems', [$controller, 'storeSystem']);
        Route::post('/systems/{system}/processes', [$controller, 'storeProcess']);
        Route::patch('/processes/{process}', [$controller, 'updateProcess']);
        Route::get('/snowball', [$controller, 'snowball']);
        Route::post('/processes/{process}/sops', [$controller, 'storeSop']);
        Route::post('/sops/{sop}/publish', [$controller, 'publishSop']);
        // Accountable ownership: runbook compilation, execution, escalation
        Route::post('/processes/{process}/automate', [$controller, 'automate']);
        Route::post('/processes/{process}/run', [$controller, 'run']);
        Route::post('/processes/{process}/resolve-escalation', [$controller, 'resolveEscalation']);
    });

    // Messaging channels (provisioned numbers + supported channels)
    Route::get('/messaging/channels', [\App\Http\Controllers\MessagingController::class, 'channels']);

    // Web-push notifications (PWA)
    Route::get('/notifications/vapid-key', [\App\Http\Controllers\NotificationController::class, 'vapidKey']);
    Route::post('/notifications/push/subscribe', [\App\Http\Controllers\NotificationController::class, 'subscribe']);
    Route::post('/notifications/push/unsubscribe', [\App\Http\Controllers\NotificationController::class, 'unsubscribe']);
    Route::get('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'preferences']);
    Route::put('/notifications/preferences', [\App\Http\Controllers\NotificationController::class, 'updatePreferences']);

    // Universal compliance discovery
    Route::get('/compliance/obligations', [ComplianceController::class, 'obligations']);

    // GDPR data-subject requests + audit export (admin; erasure/export step-up gated)
    Route::post('/compliance/dsar', [ComplianceController::class, 'createDsar'])->middleware(['role:admin', 'step.up']);
    Route::get('/compliance/dsar/{id}', [ComplianceController::class, 'showDsar'])->middleware('role:admin');
    Route::get('/compliance/dsar/{id}/download', [ComplianceController::class, 'downloadDsar'])->middleware('role:admin');
    Route::get('/compliance/audit-log/export', [ComplianceController::class, 'auditExport'])->middleware('role:admin');

    // Billing & monetization
    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::get('/billing/summary', [BillingController::class, 'summary']);
    Route::get('/billing/invoices', [BillingController::class, 'invoices']);
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->middleware('step.up');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->middleware('step.up');

    // Priestley Five A's operating rhythm (Alignment/Awareness/Accountability/Activity/Assets)
    Route::prefix('operating')->group(function () {
        Route::get('/alignment', [\App\Http\Controllers\Operating\OperatingController::class, 'showAlignment']);
        Route::put('/alignment', [\App\Http\Controllers\Operating\OperatingController::class, 'updateAlignment']);
        Route::get('/org-chart', [\App\Http\Controllers\Operating\OperatingController::class, 'orgChart']);
        Route::get('/scoreboard', [\App\Http\Controllers\Operating\OperatingController::class, 'scoreboard']);
        Route::get('/awareness', [\App\Http\Controllers\Operating\OperatingController::class, 'awareness']);
        Route::post('/awareness', [\App\Http\Controllers\Operating\OperatingController::class, 'raiseAwareness']);
        Route::post('/awareness/{id}/resolve', [\App\Http\Controllers\Operating\OperatingController::class, 'resolveAwareness']);
        Route::get('/weekly-rhythm', [\App\Http\Controllers\Operating\OperatingController::class, 'weeklyRhythm']);
        Route::get('/assets', [\App\Http\Controllers\Operating\OperatingController::class, 'assets']);
    });

    // V2 outcome loop — weekly review surface
    Route::prefix('outcomes')->group(function () {
        Route::get('/weekly-review', [OutcomesController::class, 'weeklyReview']);
        Route::get('/recommendations', [OutcomesController::class, 'recommendations']);
        Route::patch('/recommendations/{id}/accept', [OutcomesController::class, 'accept']);
        Route::patch('/recommendations/{id}/reject', [OutcomesController::class, 'reject']);
        Route::get('/autonomy', [OutcomesController::class, 'autonomy']);
        Route::put('/autonomy', [OutcomesController::class, 'updateAutonomy']);
    });

    // ─── Brain / Workspaces / Skills program (ADR-0002) ──────────────
    // One route file per stream so parallel work never edits this file:
    //   routes/api/brain.php   → /api/brain/*            (Knowledge brain)
    //   routes/api/skills.php  → /api/skills/*           (catalogue, cards, run)
    //   routes/api/agents.php  → /api/agent-runs/*, /api/artifacts/*, /api/agents/breaker
    //   routes/api/founder.php → /api/today, /api/atlas/sessions/*, /api/notifications/*
    //   routes/api/map.php     → /api/map/*                (business map)
    //   routes/api/voice.php   → /api/voice/personas, /api/me/voice, /api/atlas/speak
    //   routes/api/launch.php  → /api/launch/*             (business-launch pack)
    //   routes/api/reports.php → /api/reports/weekly/*      (Monday letter + C-Suite)
    //   routes/api/board.php   → /api/board/*              (board of advisors)
    foreach (['brain', 'skills', 'agents', 'founder', 'map', 'voice', 'launch', 'reports', 'board'] as $programRoutes) {
        $programRoutesPath = __DIR__.'/api/'.$programRoutes.'.php';
        if (is_file($programRoutesPath)) {
            require $programRoutesPath;
        }
    }
});

// ─── Admin workspace (role:admin) ───────────────────────────────
Route::middleware(['auth:sanctum', 'tenant', 'role:admin', 'throttle:admin'])->prefix('admin')->group(function () {
        // Users
        Route::get('/users', [AdminController::class, 'listUsers']);
        // Both invite and standard POST alias
        Route::post('/users',         [AdminController::class, 'inviteUser'])->middleware(['step.up', 'plan.quota:seats']);
        Route::post('/users:invite',  [AdminController::class, 'inviteUser'])->middleware(['step.up', 'plan.quota:seats']);
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
        Route::get('/readiness', [PlatformController::class, 'readiness']);

        // Feature flags
        Route::get('/feature-flags',              [PlatformController::class, 'listFlags']);
        Route::get('/feature-flags/{name}',       [PlatformController::class, 'getFlag']);
        Route::put('/feature-flags/{name}',       [PlatformController::class, 'putFlag'])->middleware('step.up');
        Route::delete('/feature-flags/{name}',    [PlatformController::class, 'deleteFlag'])->middleware('step.up');

        // Impersonation
        Route::post('/impersonate',              [PlatformController::class, 'impersonate'])->middleware('step.up');
        Route::post('/impersonate/{id}/end',     [PlatformController::class, 'endImpersonation']);
    });

// ─── Financial Services (Financial OS) ───────────────────────────────
Route::middleware(['auth:sanctum', 'tenant', 'onboarding.required', 'cost.limit', 'throttle:api'])
    ->prefix('financial')
    ->group(function () {
        // Ledger
        Route::get('/ledger', [\App\Http\Controllers\Financial\LedgerController::class, 'index']);
        Route::get('/ledger/trial-balance', [\App\Http\Controllers\Financial\LedgerController::class, 'trialBalance']);
        Route::get('/ledger/cash-flow', [\App\Http\Controllers\Financial\LedgerController::class, 'cashFlow']);
        Route::get('/ledger/accounts/{accountId}', [\App\Http\Controllers\Financial\LedgerController::class, 'generalLedger']);
        Route::post('/ledger/journal-entry', [\App\Http\Controllers\Financial\LedgerController::class, 'journalEntry']);
        Route::get('/ledger/accounts', [\App\Http\Controllers\Financial\LedgerController::class, 'accounts']);
        Route::post('/ledger/accounts', [\App\Http\Controllers\Financial\LedgerController::class, 'createAccount']);
        Route::get('/ledger/chart-of-accounts', [\App\Http\Controllers\Financial\LedgerController::class, 'chartOfAccounts']);
        Route::post('/ledger/chart-of-accounts', [\App\Http\Controllers\Financial\LedgerController::class, 'createChartAccount']);

        // Invoices — literal segments must precede /{id} or Laravel matches
        // "overdue"/"summary" as ids (findOrFail('overdue') → 404).
        Route::get('/invoices', [\App\Http\Controllers\Financial\InvoiceController::class, 'index']);
        Route::get('/invoices/overdue', [\App\Http\Controllers\Financial\InvoiceController::class, 'overdue']);
        Route::get('/invoices/summary', [\App\Http\Controllers\Financial\InvoiceController::class, 'summary']);
        Route::get('/invoices/{id}', [\App\Http\Controllers\Financial\InvoiceController::class, 'show']);
        Route::post('/invoices', [\App\Http\Controllers\Financial\InvoiceController::class, 'store']);
        Route::post('/invoices/{id}/send', [\App\Http\Controllers\Financial\InvoiceController::class, 'send']);
        Route::post('/invoices/{id}/mark-paid', [\App\Http\Controllers\Financial\InvoiceController::class, 'markPaid']);
        Route::post('/invoices/{id}/cancel', [\App\Http\Controllers\Financial\InvoiceController::class, 'cancel']);

        // Customers
        Route::get('/customers', [\App\Http\Controllers\Financial\InvoiceController::class, 'customers']);
        Route::post('/customers', [\App\Http\Controllers\Financial\InvoiceController::class, 'createCustomer']);

        // Payments — literal segments before /{id} (see invoice note above)
        Route::get('/payments', [\App\Http\Controllers\Financial\PaymentController::class, 'index']);
        Route::get('/payments/summary', [\App\Http\Controllers\Financial\PaymentController::class, 'summary']);
        Route::get('/payments/{id}', [\App\Http\Controllers\Financial\PaymentController::class, 'show']);
        Route::post('/payments', [\App\Http\Controllers\Financial\PaymentController::class, 'recordPayment']);
        Route::post('/payments/initiate', [\App\Http\Controllers\Financial\PaymentController::class, 'initiatePayment']);
        Route::get('/transactions', [\App\Http\Controllers\Financial\PaymentController::class, 'transactions']);

        // Spend — Expense management (Stage 1c). Literal segments before
        // /{id} (see invoice note above); writes are admin-gated where noted.
        Route::get('/expense-categories', [\App\Http\Controllers\Spend\ExpenseCategoryController::class, 'index']);
        Route::post('/expense-categories', [\App\Http\Controllers\Spend\ExpenseCategoryController::class, 'store'])->middleware('role:admin');
        Route::put('/expense-categories/{id}', [\App\Http\Controllers\Spend\ExpenseCategoryController::class, 'update'])->middleware('role:admin');

        Route::get('/expense-policies', [\App\Http\Controllers\Spend\ExpensePolicyController::class, 'index']);
        Route::post('/expense-policies', [\App\Http\Controllers\Spend\ExpensePolicyController::class, 'store'])->middleware('role:admin');
        Route::put('/expense-policies/{id}', [\App\Http\Controllers\Spend\ExpensePolicyController::class, 'update'])->middleware('role:admin');
        Route::delete('/expense-policies/{id}', [\App\Http\Controllers\Spend\ExpensePolicyController::class, 'destroy'])->middleware('role:admin');

        Route::get('/expenses/summary', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'summary']);
        Route::get('/expenses', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'index']);
        Route::post('/expenses', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'store']);
        Route::get('/expenses/{id}', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'show']);
        Route::put('/expenses/{id}', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'update']);
        Route::post('/expenses/{id}/items', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'addItem']);
        Route::delete('/expenses/{id}/items/{itemId}', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'removeItem']);
        Route::post('/expenses/{id}/items/{itemId}/receipt', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'attachReceipt']);
        Route::delete('/expenses/{id}/receipts/{documentId}', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'removeReceipt']);
        Route::post('/expenses/{id}/submit', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'submit']);
        Route::post('/expenses/{id}/void', [\App\Http\Controllers\Spend\ExpenseReportController::class, 'void']);

        // Spend documents — AI extraction + category automation (literal
        // /spend/categorize before the /{id} routes, see invoice note above).
        Route::post('/spend/categorize', [\App\Http\Controllers\Spend\SpendDocumentController::class, 'categorize']);
        Route::get('/spend/documents/{id}', [\App\Http\Controllers\Spend\SpendDocumentController::class, 'show']);
        Route::post('/spend/documents/{id}/confirm', [\App\Http\Controllers\Spend\SpendDocumentController::class, 'confirm']);
        Route::post('/spend/documents/{id}/retry', [\App\Http\Controllers\Spend\SpendDocumentController::class, 'retry']);

        Route::get('/reimbursements', [\App\Http\Controllers\Spend\ReimbursementController::class, 'index']);
        Route::post('/reimbursements/{id}/mark-paid', [\App\Http\Controllers\Spend\ReimbursementController::class, 'markPaid'])
            ->middleware(['role:admin', 'step.up']);
        Route::post('/reimbursements/{id}/cancel', [\App\Http\Controllers\Spend\ReimbursementController::class, 'cancel'])
            ->middleware('role:admin');

        // Spend — Bill pay / AP (Stage 2). Literal segments before /{id}
        // (see invoice note above); payment-adjacent writes are admin-gated,
        // mark-paid additionally requires fresh MFA step-up.
        Route::get('/vendors', [\App\Http\Controllers\Spend\VendorController::class, 'index']);
        Route::post('/vendors', [\App\Http\Controllers\Spend\VendorController::class, 'store']);
        Route::get('/vendors/{id}', [\App\Http\Controllers\Spend\VendorController::class, 'show']);
        Route::put('/vendors/{id}', [\App\Http\Controllers\Spend\VendorController::class, 'update']);
        Route::post('/vendors/{id}/archive', [\App\Http\Controllers\Spend\VendorController::class, 'archive']);

        Route::get('/bills/summary', [\App\Http\Controllers\Spend\BillController::class, 'summary']);
        Route::get('/bills/due-soon', [\App\Http\Controllers\Spend\BillController::class, 'dueSoon']);
        Route::get('/bills/aging', [\App\Http\Controllers\Spend\BillController::class, 'aging']);
        Route::get('/bills', [\App\Http\Controllers\Spend\BillController::class, 'index']);
        Route::post('/bills', [\App\Http\Controllers\Spend\BillController::class, 'store']);
        Route::post('/bills/upload', [\App\Http\Controllers\Spend\BillController::class, 'upload']);
        Route::get('/bills/{id}', [\App\Http\Controllers\Spend\BillController::class, 'show']);
        Route::put('/bills/{id}', [\App\Http\Controllers\Spend\BillController::class, 'update']);
        Route::post('/bills/{id}/submit', [\App\Http\Controllers\Spend\BillController::class, 'submit']);
        Route::post('/bills/{id}/schedule', [\App\Http\Controllers\Spend\BillController::class, 'schedule'])
            ->middleware('role:admin');
        Route::post('/bills/{id}/mark-paid', [\App\Http\Controllers\Spend\BillController::class, 'markPaid'])
            ->middleware(['role:admin', 'step.up']);
        Route::post('/bills/{id}/void', [\App\Http\Controllers\Spend\BillController::class, 'void'])
            ->middleware('role:admin');

        Route::get('/recurring-bills', [\App\Http\Controllers\Spend\RecurringBillController::class, 'index']);
        Route::post('/recurring-bills', [\App\Http\Controllers\Spend\RecurringBillController::class, 'store'])
            ->middleware('role:admin');
        Route::put('/recurring-bills/{id}', [\App\Http\Controllers\Spend\RecurringBillController::class, 'update'])
            ->middleware('role:admin');
        Route::delete('/recurring-bills/{id}', [\App\Http\Controllers\Spend\RecurringBillController::class, 'destroy'])
            ->middleware('role:admin');

        // Spend — Accounting automation (Stage 3). Literal segments before
        // /{id} (see invoice note above); writes are admin-gated.
        Route::get('/accounting/mappings', [\App\Http\Controllers\Spend\AccountingController::class, 'mappings']);
        Route::put('/accounting/mappings', [\App\Http\Controllers\Spend\AccountingController::class, 'updateMappings'])
            ->middleware('role:admin');
        Route::get('/accounting/rules', [\App\Http\Controllers\Spend\AccountingController::class, 'rules']);
        Route::put('/accounting/rules', [\App\Http\Controllers\Spend\AccountingController::class, 'updateRules'])
            ->middleware('role:admin');
        Route::get('/accounting/postings', [\App\Http\Controllers\Spend\AccountingController::class, 'postings']);
        Route::post('/accounting/postings/{id}/post', [\App\Http\Controllers\Spend\AccountingController::class, 'executePosting'])
            ->middleware('role:admin');
        Route::get('/accounting/exports', [\App\Http\Controllers\Spend\AccountingController::class, 'exports']);
        Route::post('/accounting/exports', [\App\Http\Controllers\Spend\AccountingController::class, 'createExport'])
            ->middleware('role:admin');
        Route::get('/accounting/exports/{id}/download', [\App\Http\Controllers\Spend\AccountingController::class, 'downloadExport'])
            ->middleware('role:admin');
        Route::get('/accounting/export-schedules', [\App\Http\Controllers\Spend\AccountingController::class, 'schedules']);
        Route::post('/accounting/export-schedules', [\App\Http\Controllers\Spend\AccountingController::class, 'storeSchedule'])
            ->middleware('role:admin');
        Route::put('/accounting/export-schedules/{id}', [\App\Http\Controllers\Spend\AccountingController::class, 'updateSchedule'])
            ->middleware('role:admin');
        Route::delete('/accounting/export-schedules/{id}', [\App\Http\Controllers\Spend\AccountingController::class, 'destroySchedule'])
            ->middleware('role:admin');
        Route::get('/spend/summary', [\App\Http\Controllers\Spend\AccountingController::class, 'spendSummary']);

        // Financial Overview
        Route::get('/dashboard', [\App\Http\Controllers\Financial\FinancialController::class, 'dashboard']);
        Route::get('/reports', [\App\Http\Controllers\Financial\FinancialController::class, 'reports']);
        Route::post('/reports/generate', [\App\Http\Controllers\Financial\FinancialController::class, 'generateReport']);
        Route::get('/aging-report', [\App\Http\Controllers\Financial\FinancialController::class, 'agingReport']);
        Route::post('/risk-check', [\App\Http\Controllers\Financial\FinancialController::class, 'checkTransactionRisk']);

        // Wallets
        Route::get('/wallets', [\App\Http\Controllers\Financial\FinancialController::class, 'wallets']);
        Route::post('/wallets', [\App\Http\Controllers\Financial\FinancialController::class, 'createWallet']);
        Route::get('/wallets/{id}/transactions', [\App\Http\Controllers\Financial\FinancialController::class, 'walletTransactions']);

        // Budgets
        Route::get('/budgets', [\App\Http\Controllers\Financial\FinancialController::class, 'budgets']);
        Route::post('/budgets', [\App\Http\Controllers\Financial\FinancialController::class, 'createBudget']);

        // Tax
        Route::get('/tax-rates', [\App\Http\Controllers\Financial\FinancialController::class, 'taxRates']);
        Route::post('/tax-rates', [\App\Http\Controllers\Financial\FinancialController::class, 'createTaxRate']);

        // Alerts
        Route::get('/alerts', [\App\Http\Controllers\Financial\FinancialController::class, 'alerts']);
        Route::post('/alerts/{alertId}/acknowledge', [\App\Http\Controllers\Financial\FinancialController::class, 'acknowledgeAlert']);

        // Portfolios & Trading
        Route::get('/portfolios', [\App\Http\Controllers\Financial\PortfolioController::class, 'index']);
        Route::get('/portfolios/{id}', [\App\Http\Controllers\Financial\PortfolioController::class, 'show']);
        Route::post('/portfolios', [\App\Http\Controllers\Financial\PortfolioController::class, 'store']);
        Route::get('/portfolios/{id}/performance', [\App\Http\Controllers\Financial\PortfolioController::class, 'performance']);
        Route::post('/portfolios/{id}/trades', [\App\Http\Controllers\Financial\PortfolioController::class, 'executeTrade']);
        Route::post('/portfolios/{id}/update-prices', [\App\Http\Controllers\Financial\PortfolioController::class, 'updatePrices']);
        Route::get('/trades', [\App\Http\Controllers\Financial\PortfolioController::class, 'trades']);
    });

// ─── Sales & CRM OS (Lead-to-Sale Funnel — sales-crm pack) ─────────────
// pack.entitled:sales-crm gates the whole group so the monetized pack's API
// requires an active purchase (or a free manifest), not just onboarding.
Route::middleware(['auth:sanctum', 'tenant', 'pack.entitled:sales-crm', 'onboarding.required', 'cost.limit', 'throttle:api'])
    ->prefix('sales')
    ->group(function () {
        Route::get('/leads', [\App\Http\Controllers\Sales\LeadController::class, 'index']);
        Route::get('/leads/pipeline-summary', [\App\Http\Controllers\Sales\LeadController::class, 'pipelineSummary']);
        Route::get('/leads/{id}', [\App\Http\Controllers\Sales\LeadController::class, 'show']);
        Route::post('/leads', [\App\Http\Controllers\Sales\LeadController::class, 'store']);
        Route::post('/leads/{id}/stage', [\App\Http\Controllers\Sales\LeadController::class, 'updateStage']);

        // Discovery interview -> script draft -> approval -> go-live
        Route::get('/readiness', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'readiness']);
        Route::get('/funnel-setup', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'show']);
        Route::post('/funnel-setup/start', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'start']);
        Route::post('/funnel-setup/answer', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'answer']);
        Route::post('/funnel-setup/draft-script', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'draftScript']);
        Route::post('/funnel-setup/request-revision', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'requestRevision']);
        Route::post('/scripts/{scriptId}/submit', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'submitScript']);
        Route::post('/scripts/{scriptId}/revise', [\App\Http\Controllers\Sales\FunnelSetupController::class, 'reviseScript']);

        // Partner outreach (affiliate recruitment): prospects, DM queue, settings.
        // Static paths first so they never match the {id} routes below.
        Route::get('/partners', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'index']);
        Route::get('/partners/dm-queue', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'dmQueue']);
        Route::get('/partners/settings', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'settings']);
        Route::put('/partners/settings', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'updateSettings'])->middleware('role:admin');
        Route::post('/partners/import', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'import'])->middleware('role:admin');
        Route::post('/partners/run', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'run'])->middleware('role:admin');
        Route::post('/partners/messages/{messageId}/mark-sent', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'markDmSent']);
        Route::patch('/partners/drafts/{messageId}', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'updateDraft']);
        Route::get('/partners/{id}', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'show']);
        Route::patch('/partners/{id}', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'update']);
        Route::post('/partners/{id}/dm-reply', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'dmReply']);
        Route::post('/partners/{id}/reply', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'reply']);
        Route::post('/partners/{id}/pause', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'pause']);
        Route::post('/partners/{id}/resume', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'resume']);
        Route::post('/partners/{id}/retire', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'retire']);
        Route::post('/partners/{id}/hand-back', [\App\Http\Controllers\Sales\PartnerProspectController::class, 'handBack']);

        // Inbox — conversations across email + WhatsApp
        Route::get('/conversations', [\App\Http\Controllers\Sales\ConversationController::class, 'index']);
        Route::get('/conversations/{id}', [\App\Http\Controllers\Sales\ConversationController::class, 'show']);
        Route::post('/conversations/{id}/reply', [\App\Http\Controllers\Sales\ConversationController::class, 'reply']);
    });

// Backend-internal — called by Python intelligence workers only (never the
// cockpit). Shared-key auth via X-Internal-Key, tenant scope via X-Tenant-Id.
Route::prefix('internal')->middleware('internal.key')->group(function () {
    // Program internal routes (tool gateway / brain reads for the Python plane)
    if (is_file(__DIR__.'/api/internal.php')) {
        require __DIR__.'/api/internal.php';
    }
    Route::post('/sales/leads/{id}/stage', [\App\Http\Controllers\Internal\SalesController::class, 'updateStage']);
    Route::post('/sales/leads/{id}/score', [\App\Http\Controllers\Internal\SalesController::class, 'updateScore']);
    Route::post('/sales/leads/{id}/message', [\App\Http\Controllers\Internal\SalesController::class, 'sendMessage']);
    Route::post('/sales/leads/{id}/enroll', [\App\Http\Controllers\Internal\SalesController::class, 'enrollInSequence']);
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
