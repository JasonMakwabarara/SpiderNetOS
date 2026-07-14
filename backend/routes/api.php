<?php
use Illuminate\Support\Facades\Route;

Route::post('/login', [App\Http\Controllers\AuthController::class, 'login']);
Route::get('/health', function () { return response()->json(['status' => 'healthy']); });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function ($request) { return $request->user(); });

    // Agents
    Route::get('/agents', [App\Http\Controllers\AgentController::class, 'index']);
    Route::post('/agents', [App\Http\Controllers\AgentController::class, 'store']);
    Route::get('/agents/{id}', [App\Http\Controllers\AgentController::class, 'show']);
    Route::put('/agents/{id}', [App\Http\Controllers\AgentController::class, 'update']);
    Route::delete('/agents/{id}', [App\Http\Controllers\AgentController::class, 'destroy']);
    Route::post('/agents/chat', [App\Http\Controllers\AgentController::class, 'chat']);
    Route::post('/agents/execute-task', [App\Http\Controllers\AgentController::class, 'executeTask']);

    // Flows
    Route::get('/flows', [App\Http\Controllers\FlowController::class, 'index']);
    Route::post('/flows', [App\Http\Controllers\FlowController::class, 'store']);
    Route::get('/flows/{id}', [App\Http\Controllers\FlowController::class, 'show']);
    Route::put('/flows/{id}', [App\Http\Controllers\FlowController::class, 'update']);
    Route::delete('/flows/{id}', [App\Http\Controllers\FlowController::class, 'destroy']);
    Route::post('/flows/{id}/execute', [App\Http\Controllers\FlowController::class, 'execute']);
    Route::post('/flows/{id}/publish', [App\Http\Controllers\FlowController::class, 'publish']);

    // Atlas
    Route::post('/atlas/chat', [App\Http\Controllers\AtlasController::class, 'chat']);
    Route::post('/atlas/intent', [App\Http\Controllers\AtlasIntentController::class, 'handleIntent']);

    // Analytics
    Route::get('/analytics/agents', [App\Http\Controllers\AnalyticsController::class, 'agents']);
    Route::get('/analytics/flows', [App\Http\Controllers\AnalyticsController::class, 'flows']);
    Route::get('/analytics/tickets', [App\Http\Controllers\AnalyticsController::class, 'tickets']);
    Route::get('/analytics/crm', [App\Http\Controllers\AnalyticsController::class, 'crm']);
    Route::post('/analytics/generate-report', [App\Http\Controllers\AnalyticsController::class, 'generateReport']);

    // Reports
    Route::get('/reports', [App\Http\Controllers\ReportController::class, 'index']);
    Route::post('/reports', [App\Http\Controllers\ReportController::class, 'store']);
    Route::post('/reports/generate', [App\Http\Controllers\ReportController::class, 'generate']);
    Route::get('/reports/{id}', [App\Http\Controllers\ReportController::class, 'show']);
    Route::delete('/reports/{id}', [App\Http\Controllers\ReportController::class, 'destroy']);

    // Tickets
    Route::get('/tickets', [App\Http\Controllers\TicketController::class, 'index']);
    Route::post('/tickets', [App\Http\Controllers\TicketController::class, 'store']);
    Route::get('/tickets/{id}', [App\Http\Controllers\TicketController::class, 'show']);
    Route::put('/tickets/{id}', [App\Http\Controllers\TicketController::class, 'update']);
    Route::delete('/tickets/{id}', [App\Http\Controllers\TicketController::class, 'destroy']);

    // CRM
    Route::get('/crm', [App\Http\Controllers\CrmController::class, 'index']);
    Route::post('/crm', [App\Http\Controllers\CrmController::class, 'store']);
    Route::get('/crm/{id}', [App\Http\Controllers\CrmController::class, 'show']);
    Route::delete('/crm/{id}', [App\Http\Controllers\CrmController::class, 'destroy']);

    // Security
    Route::get('/security/audit', [App\Http\Controllers\SecurityController::class, 'audit']);
    Route::post('/security/2fa/enable', [App\Http\Controllers\SecurityController::class, 'enableTwoFactor']);
    Route::post('/security/2fa/verify', [App\Http\Controllers\SecurityController::class, 'verifyTwoFactor']);
    Route::post('/security/2fa/disable', [App\Http\Controllers\SecurityController::class, 'disableTwoFactor']);
});
<<<<<<< HEAD
=======

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

// Billing webhooks (no auth — Standard Webhooks signature is the gate)
Route::post('/webhooks/dodo', [\App\Http\Controllers\Billing\DodoWebhookController::class, 'handle'])
    ->middleware('throttle:billing_webhook');

// Enterprise self-serve registration funnel (public, heavily throttled,
// kill-switched via config('enterprise.self_serve_enabled')).
Route::prefix('enterprise/register')->middleware('throttle:enterprise_register')->group(function () {
    $controller = \App\Http\Controllers\Enterprise\EnterpriseRegistrationController::class;

    Route::post('/start', [$controller, 'start']);
    Route::post('/verify-domain', [$controller, 'verifyDomain']);
    Route::post('/create-tenant', [$controller, 'createTenant']);
    Route::post('/scim/generate', [$controller, 'scimGenerate']);
    Route::post('/bundle/create', [$controller, 'bundleCreate']);
    Route::post('/deploy/start', [$controller, 'deployStart']);
});

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
    
    // Flows (DAG execution)
    Route::apiResource('flows', FlowController::class);
    Route::post('/flows/quick-create', [FlowController::class, 'quickCreate']);
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
    Route::post('/integrations/{provider}/authorize', [IntegrationsController::class, 'authorize']);
    Route::post('/integrations/calendar/book',        [CalendarController::class, 'book']);

    // Feature Packs (Phase 3)
    Route::get('/feature-packs/catalogue', [FeaturePackController::class, 'catalogue']);
    Route::get('/feature-packs/recommendations', [FeaturePackController::class, 'recommendations']);
    Route::post('/feature-packs/signals', [FeaturePackController::class, 'recordSignal']);
    Route::post('/feature-packs/feedback', [FeaturePackController::class, 'feedback']);
    Route::get('/feature-packs', [FeaturePackController::class, 'index']);
    Route::post('/feature-packs/{id}/install', [FeaturePackController::class, 'install']);
    Route::get('/feature-packs/{id}', [FeaturePackController::class, 'show']);

    // Business profile (Atlas discovery learning loop)
    Route::get('/business-profile', [BusinessProfileController::class, 'show']);
    Route::put('/business-profile', [BusinessProfileController::class, 'update']);

    // Universal compliance discovery
    Route::get('/compliance/obligations', [ComplianceController::class, 'obligations']);

    // Billing & monetization (Dodo Payments)
    Route::get('/billing/summary', [BillingController::class, 'summary']);
    Route::get('/billing/plans', [BillingController::class, 'plans']);
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware('role:admin');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->middleware(['role:admin', 'step.up']);

    // Business Systemization pack — systems map, ownership, snowball, SOPs
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

    // V2 outcome loop — weekly review surface
    Route::prefix('outcomes')->group(function () {
        Route::get('/weekly-review', [OutcomesController::class, 'weeklyReview']);
        Route::get('/recommendations', [OutcomesController::class, 'recommendations']);
        Route::patch('/recommendations/{id}/accept', [OutcomesController::class, 'accept']);
        Route::patch('/recommendations/{id}/reject', [OutcomesController::class, 'reject']);
        Route::get('/autonomy', [OutcomesController::class, 'autonomy']);
        Route::put('/autonomy', [OutcomesController::class, 'updateAutonomy']);
    });
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

        // Invoices
        Route::get('/invoices', [\App\Http\Controllers\Financial\InvoiceController::class, 'index']);
        Route::get('/invoices/{id}', [\App\Http\Controllers\Financial\InvoiceController::class, 'show']);
        Route::post('/invoices', [\App\Http\Controllers\Financial\InvoiceController::class, 'store']);
        Route::post('/invoices/{id}/send', [\App\Http\Controllers\Financial\InvoiceController::class, 'send']);
        Route::post('/invoices/{id}/mark-paid', [\App\Http\Controllers\Financial\InvoiceController::class, 'markPaid']);
        Route::post('/invoices/{id}/cancel', [\App\Http\Controllers\Financial\InvoiceController::class, 'cancel']);
        Route::get('/invoices/overdue', [\App\Http\Controllers\Financial\InvoiceController::class, 'overdue']);
        Route::get('/invoices/summary', [\App\Http\Controllers\Financial\InvoiceController::class, 'summary']);

        // Customers
        Route::get('/customers', [\App\Http\Controllers\Financial\InvoiceController::class, 'customers']);
        Route::post('/customers', [\App\Http\Controllers\Financial\InvoiceController::class, 'createCustomer']);

        // Payments
        Route::get('/payments', [\App\Http\Controllers\Financial\PaymentController::class, 'index']);
        Route::get('/payments/{id}', [\App\Http\Controllers\Financial\PaymentController::class, 'show']);
        Route::post('/payments', [\App\Http\Controllers\Financial\PaymentController::class, 'recordPayment']);
        Route::post('/payments/initiate', [\App\Http\Controllers\Financial\PaymentController::class, 'initiatePayment']);
        Route::get('/transactions', [\App\Http\Controllers\Financial\PaymentController::class, 'transactions']);
        Route::get('/payments/summary', [\App\Http\Controllers\Financial\PaymentController::class, 'summary']);

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
>>>>>>> 322fbcebbda24e965b4572c721e1821221088217
