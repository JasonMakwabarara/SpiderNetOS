<?php

use App\Http\Controllers\Admin\OnboardingController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AgentController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\ApprovalPolicyController;
use App\Http\Controllers\AtlasController;
use App\Http\Controllers\AtlasCopyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BriefController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\CommandController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\Enterprise\EnterpriseAuthController;
use App\Http\Controllers\Enterprise\EnterpriseRegistrationController;
use App\Http\Controllers\FeaturePackController;
use App\Http\Controllers\Financial\FinancialController;
use App\Http\Controllers\Financial\InvoiceController;
use App\Http\Controllers\Financial\LedgerController;
use App\Http\Controllers\Financial\PaymentController;
use App\Http\Controllers\Financial\PortfolioController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Integrations\CalendarController;
use App\Http\Controllers\Integrations\IntegrationsController;
use App\Http\Controllers\IntelligenceProxyController;
use App\Http\Controllers\Internal\SalesController;
use App\Http\Controllers\MessagingController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ObservabilityController;
use App\Http\Controllers\Operating\OperatingController;
use App\Http\Controllers\OutcomesController;
use App\Http\Controllers\Outreach\LinkedInSettingsController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\Sales\ConversationController;
use App\Http\Controllers\Sales\FunnelSetupController;
use App\Http\Controllers\Sales\LeadController;
use App\Http\Controllers\Sales\PartnerProspectController;
use App\Http\Controllers\Sales\PublicLeadController;
use App\Http\Controllers\Sales\PublicOutreachController;
use App\Http\Controllers\ShareLinkController;
use App\Http\Controllers\Spend\AccountingController;
use App\Http\Controllers\Spend\BillController;
use App\Http\Controllers\Spend\ExpenseCategoryController;
use App\Http\Controllers\Spend\ExpensePolicyController;
use App\Http\Controllers\Spend\ExpenseReportController;
use App\Http\Controllers\Spend\RecurringBillController;
use App\Http\Controllers\Spend\ReimbursementController;
use App\Http\Controllers\Spend\SpendDocumentController;
use App\Http\Controllers\Spend\VendorController;
use App\Http\Controllers\StateEngineController;
use App\Http\Controllers\SystemizationController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\VoiceStreamController;
use App\Http\Controllers\Webhooks\AffonsoWebhookController;
use App\Http\Controllers\Webhooks\DodoWebhookController;
use App\Http\Controllers\WhatsAppController;
use Illuminate\Support\Facades\Route;

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
Route::post('/public/lead-capture/{tenant}', [PublicLeadController::class, 'store'])
    ->middleware('throttle:lead_capture');

// Partner outreach unsubscribe (token-gated; GET confirms, POST acts — also
// the RFC 8058 one-click target advertised in List-Unsubscribe).
Route::get('/public/outreach/unsubscribe/{token}', [PublicOutreachController::class, 'confirm'])
    ->middleware('throttle:lead_capture');
Route::post('/public/outreach/unsubscribe/{token}', [PublicOutreachController::class, 'unsubscribe'])
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
    Route::post('/inbound', [VoiceController::class, 'inbound']);
    Route::post('/gather', [VoiceController::class, 'gather']);
    Route::post('/status', [VoiceController::class, 'status'])->withoutMiddleware(['voice.feature_flag']);
    Route::post('/recording', [VoiceController::class, 'recording'])->withoutMiddleware(['voice.feature_flag']);
});

// WhatsApp — Twilio Messages webhooks (no auth, Twilio-signed). Reuses
// voice.verify_twilio: the Twilio HMAC-SHA1 signature scheme is identical
// across products (URL + sorted POST params), not voice-specific.
Route::prefix('whatsapp')->middleware(['throttle:voice_webhook', 'voice.verify_twilio'])->group(function () {
    Route::post('/inbound', [WhatsAppController::class, 'inbound']);
    Route::post('/status', [WhatsAppController::class, 'status']);
});

// Dodo Payments — purchase webhooks (no auth, signature-verified)
// Affonso affiliate webhooks (no auth; X-Affonso-Signature verified per tenant)
Route::post('/webhooks/affonso/{tenant}', [AffonsoWebhookController::class, 'handle'])
    ->middleware(['affonso.verify_signature', 'throttle:payment_webhook']);

Route::post('/webhooks/dodo', [DodoWebhookController::class, 'handle'])
    ->middleware(['throttle:payment_webhook', 'dodo.verify_signature']);

// Enterprise self-serve registration funnel (public, heavily throttled,
// kill-switched via config('enterprise.self_serve_enabled')). Restored from
// real trunk e31d996 — prod serves this today.
Route::prefix('enterprise/register')->middleware('throttle:enterprise_register')->group(function () {
    $controller = EnterpriseRegistrationController::class;

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
    $controller = EnterpriseAuthController::class;

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
    Route::get('/approvals/policies', [ApprovalPolicyController::class, 'index']);
    Route::post('/approvals/policies', [ApprovalPolicyController::class, 'store'])->middleware('role:admin');
    Route::put('/approvals/policies/{id}', [ApprovalPolicyController::class, 'update'])->middleware('role:admin');
    Route::delete('/approvals/policies/{id}', [ApprovalPolicyController::class, 'destroy'])->middleware('role:admin');
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
    Route::get('/atlas/copy', [AtlasCopyController::class, 'serve']);
    Route::post('/atlas/copy/{variantId}/event', [AtlasCopyController::class, 'recordEvent']);

    // Voice AI — Management (authenticated)
    Route::post('/voice/call', [VoiceController::class, 'initiateCall']);
    Route::get('/voice/calls', [VoiceController::class, 'listCalls']);
    // Phase D additions
    Route::get('/voice/calls/{id}', [VoiceController::class, 'showCall']);
    Route::get('/voice/calls/{id}/transcript.txt', [VoiceController::class, 'exportTranscript']);
    Route::get('/voice/numbers', [VoiceController::class, 'listNumbers']);
    Route::post('/voice/numbers', [VoiceController::class, 'createNumber']);
    Route::patch('/voice/numbers/{id}', [VoiceController::class, 'updateNumber']);
    Route::delete('/voice/numbers/{id}', [VoiceController::class, 'deleteNumber']);
    Route::get('/voice/quotas', [VoiceController::class, 'getQuotas']);
    Route::put('/voice/quotas', [VoiceController::class, 'updateQuotas']);
    // Integrations (Phase D)
    Route::get('/integrations', [IntegrationsController::class, 'index']);
    Route::get('/integrations/catalogue', [IntegrationsController::class, 'catalogue']);
    Route::post('/integrations/{provider}/authorize', [IntegrationsController::class, 'authorize']);
    Route::post('/integrations/{provider}/test', [IntegrationsController::class, 'test']);
    Route::post('/integrations/{provider}/actions/{action}', [IntegrationsController::class, 'execute']);
    Route::delete('/integrations/{provider}', [IntegrationsController::class, 'destroy']);
    Route::post('/integrations/calendar/book', [CalendarController::class, 'book']);

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
        $controller = SystemizationController::class;

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
    Route::get('/messaging/channels', [MessagingController::class, 'channels']);

    // Web-push notifications (PWA)
    Route::get('/notifications/vapid-key', [NotificationController::class, 'vapidKey']);
    Route::post('/notifications/push/subscribe', [NotificationController::class, 'subscribe']);
    Route::post('/notifications/push/unsubscribe', [NotificationController::class, 'unsubscribe']);
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
    Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences']);

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
        Route::get('/alignment', [OperatingController::class, 'showAlignment']);
        Route::put('/alignment', [OperatingController::class, 'updateAlignment']);
        Route::get('/org-chart', [OperatingController::class, 'orgChart']);
        Route::get('/scoreboard', [OperatingController::class, 'scoreboard']);
        Route::get('/awareness', [OperatingController::class, 'awareness']);
        Route::post('/awareness', [OperatingController::class, 'raiseAwareness']);
        Route::post('/awareness/{id}/resolve', [OperatingController::class, 'resolveAwareness']);
        Route::get('/weekly-rhythm', [OperatingController::class, 'weeklyRhythm']);
        Route::get('/assets', [OperatingController::class, 'assets']);
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
    //   routes/api/hannah.php  → /api/hannah/*             (Hannah AI hand-off)
    foreach (['brain', 'skills', 'agents', 'founder', 'map', 'voice', 'launch', 'reports', 'board', 'hannah'] as $programRoutes) {
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
    Route::post('/users', [AdminController::class, 'inviteUser'])->middleware(['step.up', 'plan.quota:seats']);
    Route::post('/users:invite', [AdminController::class, 'inviteUser'])->middleware(['step.up', 'plan.quota:seats']);
    Route::patch('/users/{id}', [AdminController::class, 'updateUser'])->middleware('step.up');
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser'])->middleware('step.up');

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
    Route::get('/feature-flags', [PlatformController::class, 'listFlags']);
    Route::get('/feature-flags/{name}', [PlatformController::class, 'getFlag']);
    Route::put('/feature-flags/{name}', [PlatformController::class, 'putFlag'])->middleware('step.up');
    Route::delete('/feature-flags/{name}', [PlatformController::class, 'deleteFlag'])->middleware('step.up');

    // Impersonation
    Route::post('/impersonate', [PlatformController::class, 'impersonate'])->middleware('step.up');
    Route::post('/impersonate/{id}/end', [PlatformController::class, 'endImpersonation']);
});

// ─── Financial Services (Financial OS) ───────────────────────────────
Route::middleware(['auth:sanctum', 'tenant', 'onboarding.required', 'cost.limit', 'throttle:api'])
    ->prefix('financial')
    ->group(function () {
        // Ledger
        Route::get('/ledger', [LedgerController::class, 'index']);
        Route::get('/ledger/trial-balance', [LedgerController::class, 'trialBalance']);
        Route::get('/ledger/cash-flow', [LedgerController::class, 'cashFlow']);
        Route::get('/ledger/accounts/{accountId}', [LedgerController::class, 'generalLedger']);
        Route::post('/ledger/journal-entry', [LedgerController::class, 'journalEntry']);
        Route::get('/ledger/accounts', [LedgerController::class, 'accounts']);
        Route::post('/ledger/accounts', [LedgerController::class, 'createAccount']);
        Route::get('/ledger/chart-of-accounts', [LedgerController::class, 'chartOfAccounts']);
        Route::post('/ledger/chart-of-accounts', [LedgerController::class, 'createChartAccount']);

        // Invoices — literal segments must precede /{id} or Laravel matches
        // "overdue"/"summary" as ids (findOrFail('overdue') → 404).
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::get('/invoices/overdue', [InvoiceController::class, 'overdue']);
        Route::get('/invoices/summary', [InvoiceController::class, 'summary']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::post('/invoices/{id}/send', [InvoiceController::class, 'send']);
        Route::post('/invoices/{id}/mark-paid', [InvoiceController::class, 'markPaid']);
        Route::post('/invoices/{id}/cancel', [InvoiceController::class, 'cancel']);

        // Customers
        Route::get('/customers', [InvoiceController::class, 'customers']);
        Route::post('/customers', [InvoiceController::class, 'createCustomer']);

        // Payments — literal segments before /{id} (see invoice note above)
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/summary', [PaymentController::class, 'summary']);
        Route::get('/payments/{id}', [PaymentController::class, 'show']);
        Route::post('/payments', [PaymentController::class, 'recordPayment']);
        Route::post('/payments/initiate', [PaymentController::class, 'initiatePayment']);
        Route::get('/transactions', [PaymentController::class, 'transactions']);

        // Spend — Expense management (Stage 1c). Literal segments before
        // /{id} (see invoice note above); writes are admin-gated where noted.
        Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);
        Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('role:admin');
        Route::put('/expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->middleware('role:admin');

        Route::get('/expense-policies', [ExpensePolicyController::class, 'index']);
        Route::post('/expense-policies', [ExpensePolicyController::class, 'store'])->middleware('role:admin');
        Route::put('/expense-policies/{id}', [ExpensePolicyController::class, 'update'])->middleware('role:admin');
        Route::delete('/expense-policies/{id}', [ExpensePolicyController::class, 'destroy'])->middleware('role:admin');

        Route::get('/expenses/summary', [ExpenseReportController::class, 'summary']);
        Route::get('/expenses', [ExpenseReportController::class, 'index']);
        Route::post('/expenses', [ExpenseReportController::class, 'store']);
        Route::get('/expenses/{id}', [ExpenseReportController::class, 'show']);
        Route::put('/expenses/{id}', [ExpenseReportController::class, 'update']);
        Route::post('/expenses/{id}/items', [ExpenseReportController::class, 'addItem']);
        Route::delete('/expenses/{id}/items/{itemId}', [ExpenseReportController::class, 'removeItem']);
        Route::post('/expenses/{id}/items/{itemId}/receipt', [ExpenseReportController::class, 'attachReceipt']);
        Route::delete('/expenses/{id}/receipts/{documentId}', [ExpenseReportController::class, 'removeReceipt']);
        Route::post('/expenses/{id}/submit', [ExpenseReportController::class, 'submit']);
        Route::post('/expenses/{id}/void', [ExpenseReportController::class, 'void']);

        // Spend documents — AI extraction + category automation (literal
        // /spend/categorize before the /{id} routes, see invoice note above).
        Route::post('/spend/categorize', [SpendDocumentController::class, 'categorize']);
        Route::get('/spend/documents/{id}', [SpendDocumentController::class, 'show']);
        Route::post('/spend/documents/{id}/confirm', [SpendDocumentController::class, 'confirm']);
        Route::post('/spend/documents/{id}/retry', [SpendDocumentController::class, 'retry']);

        Route::get('/reimbursements', [ReimbursementController::class, 'index']);
        Route::post('/reimbursements/{id}/mark-paid', [ReimbursementController::class, 'markPaid'])
            ->middleware(['role:admin', 'step.up']);
        Route::post('/reimbursements/{id}/cancel', [ReimbursementController::class, 'cancel'])
            ->middleware('role:admin');

        // Spend — Bill pay / AP (Stage 2). Literal segments before /{id}
        // (see invoice note above); payment-adjacent writes are admin-gated,
        // mark-paid additionally requires fresh MFA step-up.
        Route::get('/vendors', [VendorController::class, 'index']);
        Route::post('/vendors', [VendorController::class, 'store']);
        Route::get('/vendors/{id}', [VendorController::class, 'show']);
        Route::put('/vendors/{id}', [VendorController::class, 'update']);
        Route::post('/vendors/{id}/archive', [VendorController::class, 'archive']);

        Route::get('/bills/summary', [BillController::class, 'summary']);
        Route::get('/bills/due-soon', [BillController::class, 'dueSoon']);
        Route::get('/bills/aging', [BillController::class, 'aging']);
        Route::get('/bills', [BillController::class, 'index']);
        Route::post('/bills', [BillController::class, 'store']);
        Route::post('/bills/upload', [BillController::class, 'upload']);
        Route::get('/bills/{id}', [BillController::class, 'show']);
        Route::put('/bills/{id}', [BillController::class, 'update']);
        Route::post('/bills/{id}/submit', [BillController::class, 'submit']);
        Route::post('/bills/{id}/schedule', [BillController::class, 'schedule'])
            ->middleware('role:admin');
        Route::post('/bills/{id}/mark-paid', [BillController::class, 'markPaid'])
            ->middleware(['role:admin', 'step.up']);
        Route::post('/bills/{id}/void', [BillController::class, 'void'])
            ->middleware('role:admin');

        Route::get('/recurring-bills', [RecurringBillController::class, 'index']);
        Route::post('/recurring-bills', [RecurringBillController::class, 'store'])
            ->middleware('role:admin');
        Route::put('/recurring-bills/{id}', [RecurringBillController::class, 'update'])
            ->middleware('role:admin');
        Route::delete('/recurring-bills/{id}', [RecurringBillController::class, 'destroy'])
            ->middleware('role:admin');

        // Spend — Accounting automation (Stage 3). Literal segments before
        // /{id} (see invoice note above); writes are admin-gated.
        Route::get('/accounting/mappings', [AccountingController::class, 'mappings']);
        Route::put('/accounting/mappings', [AccountingController::class, 'updateMappings'])
            ->middleware('role:admin');
        Route::get('/accounting/rules', [AccountingController::class, 'rules']);
        Route::put('/accounting/rules', [AccountingController::class, 'updateRules'])
            ->middleware('role:admin');
        Route::get('/accounting/postings', [AccountingController::class, 'postings']);
        Route::post('/accounting/postings/{id}/post', [AccountingController::class, 'executePosting'])
            ->middleware('role:admin');
        Route::get('/accounting/exports', [AccountingController::class, 'exports']);
        Route::post('/accounting/exports', [AccountingController::class, 'createExport'])
            ->middleware('role:admin');
        Route::get('/accounting/exports/{id}/download', [AccountingController::class, 'downloadExport'])
            ->middleware('role:admin');
        Route::get('/accounting/export-schedules', [AccountingController::class, 'schedules']);
        Route::post('/accounting/export-schedules', [AccountingController::class, 'storeSchedule'])
            ->middleware('role:admin');
        Route::put('/accounting/export-schedules/{id}', [AccountingController::class, 'updateSchedule'])
            ->middleware('role:admin');
        Route::delete('/accounting/export-schedules/{id}', [AccountingController::class, 'destroySchedule'])
            ->middleware('role:admin');
        Route::get('/spend/summary', [AccountingController::class, 'spendSummary']);

        // Financial Overview
        Route::get('/dashboard', [FinancialController::class, 'dashboard']);
        Route::get('/reports', [FinancialController::class, 'reports']);
        Route::post('/reports/generate', [FinancialController::class, 'generateReport']);
        Route::get('/aging-report', [FinancialController::class, 'agingReport']);
        Route::post('/risk-check', [FinancialController::class, 'checkTransactionRisk']);

        // Wallets
        Route::get('/wallets', [FinancialController::class, 'wallets']);
        Route::post('/wallets', [FinancialController::class, 'createWallet']);
        Route::get('/wallets/{id}/transactions', [FinancialController::class, 'walletTransactions']);

        // Budgets
        Route::get('/budgets', [FinancialController::class, 'budgets']);
        Route::post('/budgets', [FinancialController::class, 'createBudget']);

        // Tax
        Route::get('/tax-rates', [FinancialController::class, 'taxRates']);
        Route::post('/tax-rates', [FinancialController::class, 'createTaxRate']);

        // Alerts
        Route::get('/alerts', [FinancialController::class, 'alerts']);
        Route::post('/alerts/{alertId}/acknowledge', [FinancialController::class, 'acknowledgeAlert']);

        // Portfolios & Trading
        Route::get('/portfolios', [PortfolioController::class, 'index']);
        Route::get('/portfolios/{id}', [PortfolioController::class, 'show']);
        Route::post('/portfolios', [PortfolioController::class, 'store']);
        Route::get('/portfolios/{id}/performance', [PortfolioController::class, 'performance']);
        Route::post('/portfolios/{id}/trades', [PortfolioController::class, 'executeTrade']);
        Route::post('/portfolios/{id}/update-prices', [PortfolioController::class, 'updatePrices']);
        Route::get('/trades', [PortfolioController::class, 'trades']);
    });

// ─── Sales & CRM OS (Lead-to-Sale Funnel — sales-crm pack) ─────────────
// pack.entitled:sales-crm gates the whole group so the monetized pack's API
// requires an active purchase (or a free manifest), not just onboarding.
Route::middleware(['auth:sanctum', 'tenant', 'pack.entitled:sales-crm', 'onboarding.required', 'cost.limit', 'throttle:api'])
    ->prefix('sales')
    ->group(function () {
        Route::get('/leads', [LeadController::class, 'index']);
        Route::get('/leads/pipeline-summary', [LeadController::class, 'pipelineSummary']);
        Route::get('/leads/{id}', [LeadController::class, 'show']);
        Route::post('/leads', [LeadController::class, 'store']);
        Route::post('/leads/{id}/stage', [LeadController::class, 'updateStage']);

        // Discovery interview -> script draft -> approval -> go-live
        Route::get('/readiness', [FunnelSetupController::class, 'readiness']);
        Route::get('/funnel-setup', [FunnelSetupController::class, 'show']);
        Route::post('/funnel-setup/start', [FunnelSetupController::class, 'start']);
        Route::post('/funnel-setup/answer', [FunnelSetupController::class, 'answer']);
        Route::post('/funnel-setup/draft-script', [FunnelSetupController::class, 'draftScript']);
        Route::post('/funnel-setup/request-revision', [FunnelSetupController::class, 'requestRevision']);
        Route::post('/scripts/{scriptId}/submit', [FunnelSetupController::class, 'submitScript']);
        Route::post('/scripts/{scriptId}/revise', [FunnelSetupController::class, 'reviseScript']);

        // Partner outreach (affiliate recruitment): prospects, DM queue, settings.
        // Static paths first so they never match the {id} routes below.
        Route::get('/partners', [PartnerProspectController::class, 'index']);
        Route::get('/partners/dm-queue', [PartnerProspectController::class, 'dmQueue']);
        Route::get('/partners/settings', [PartnerProspectController::class, 'settings']);

        // LinkedIn outreach (plan D7 §3). Default draft_only: Richard drafts,
        // a person sends, and nothing touches LinkedIn. Moving to assisted
        // needs a named, versioned acknowledgement of LinkedIn's terms.
        Route::get('/partners/linkedin/settings', [LinkedInSettingsController::class, 'show']);
        Route::put('/partners/linkedin/settings', [LinkedInSettingsController::class, 'update'])->middleware('role:admin');
        Route::put('/partners/settings', [PartnerProspectController::class, 'updateSettings'])->middleware('role:admin');
        Route::post('/partners/import', [PartnerProspectController::class, 'import'])->middleware('role:admin');
        Route::post('/partners/run', [PartnerProspectController::class, 'run'])->middleware('role:admin');
        Route::post('/partners/messages/{messageId}/mark-sent', [PartnerProspectController::class, 'markDmSent']);
        Route::patch('/partners/drafts/{messageId}', [PartnerProspectController::class, 'updateDraft']);
        Route::get('/partners/{id}', [PartnerProspectController::class, 'show']);
        Route::patch('/partners/{id}', [PartnerProspectController::class, 'update']);
        Route::post('/partners/{id}/dm-reply', [PartnerProspectController::class, 'dmReply']);
        Route::post('/partners/{id}/reply', [PartnerProspectController::class, 'reply']);
        Route::post('/partners/{id}/pause', [PartnerProspectController::class, 'pause']);
        Route::post('/partners/{id}/resume', [PartnerProspectController::class, 'resume']);
        Route::post('/partners/{id}/retire', [PartnerProspectController::class, 'retire']);
        Route::post('/partners/{id}/hand-back', [PartnerProspectController::class, 'handBack']);

        // Inbox — conversations across email + WhatsApp
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show']);
        Route::post('/conversations/{id}/reply', [ConversationController::class, 'reply']);
    });

// Backend-internal — called by Python intelligence workers only (never the
// cockpit). Shared-key auth via X-Internal-Key, tenant scope via X-Tenant-Id.
Route::prefix('internal')->middleware('internal.key')->group(function () {
    // Program internal routes (tool gateway / brain reads for the Python plane)
    if (is_file(__DIR__.'/api/internal.php')) {
        require __DIR__.'/api/internal.php';
    }
    Route::post('/sales/leads/{id}/stage', [SalesController::class, 'updateStage']);
    Route::post('/sales/leads/{id}/score', [SalesController::class, 'updateScore']);
    Route::post('/sales/leads/{id}/message', [SalesController::class, 'sendMessage']);
    Route::post('/sales/leads/{id}/enroll', [SalesController::class, 'enrollInSequence']);
});

// ─── State Transition Engine (STE) — read-first, super_admin only ──────────
Route::middleware(['auth:sanctum', 'role:super_admin', 'can.do:ste.view'])
    ->prefix('ste')
    ->group(function () {
        Route::get('/matrix', [StateEngineController::class, 'matrix']);
        Route::get('/matrix/conditional', [StateEngineController::class, 'conditionalMatrix']);
        Route::get('/dropoffs', [StateEngineController::class, 'dropoffs']);
        Route::get('/winning-tags', [StateEngineController::class, 'winningTags']);
        Route::get('/unmapped', [StateEngineController::class, 'unmapped']);
        Route::get('/lag', [StateEngineController::class, 'lag']);
        Route::post('/simulate', [StateEngineController::class, 'simulate'])
            ->middleware('can.do:ste.simulate');
    });
