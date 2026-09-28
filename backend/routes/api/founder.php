<?php

use App\Http\Controllers\Agents\AgentBreakerController;
use App\Http\Controllers\Atlas\AtlasThreadController;
use App\Http\Controllers\Founder\ApprovalReviewController;
use App\Http\Controllers\Founder\TodayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Founder loop (plan D8 #3, #4, #6, #8, #13) — Stream D
|--------------------------------------------------------------------------
| Included from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api),
| so every route here is already tenant-scoped.
*/

// Needs-You Today — the deterministic morning brief (max 7 ranked items).
Route::get('/today', [TodayController::class, 'index']);

// Atlas context threads — cockpit/src/stores/atlas.js already calls these.
Route::get('/atlas/sessions', [AtlasThreadController::class, 'index']);
Route::post('/atlas/sessions', [AtlasThreadController::class, 'store']);
Route::get('/atlas/sessions/{id}', [AtlasThreadController::class, 'show']);
Route::patch('/atlas/sessions/{id}', [AtlasThreadController::class, 'update']);

// Approval reviews — dwell / diff / edited so the gate counts real reviews.
Route::post('/approvals/{id}/review', [ApprovalReviewController::class, 'store']);

// Circuit breaker — Pause everything / Pause one agent / Stop sends but keep drafting.
Route::get('/agents/breaker', [AgentBreakerController::class, 'index']);
Route::post('/agents/breaker', [AgentBreakerController::class, 'store'])->middleware('role:admin');
