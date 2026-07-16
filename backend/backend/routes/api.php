<?php

use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/login', [App\Http\Controllers\AuthController::class, 'login']);
Route::get('/health', function () { return response()->json(['status' => 'healthy']); });

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // User
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
