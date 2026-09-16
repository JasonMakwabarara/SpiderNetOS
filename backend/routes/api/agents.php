<?php

use App\Http\Controllers\Agents\AgentRunController;
use App\Http\Controllers\Agents\AgentWorkspaceController;
use App\Http\Controllers\Agents\ArtifactController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Operating brain — agent runs, artifacts, workspaces (ADR-0002, plan D3)
|--------------------------------------------------------------------------
| Required from the protected group in routes/api.php (auth:sanctum +
| tenant + onboarding.required + cost.limit + throttle:api).
| POST /skills/{slug}/run lives in routes/api/skills.php (Stream B1) and
| points at App\Http\Controllers\Agents\SkillRunController@run.
*/

Route::get('/agent-runs', [AgentRunController::class, 'index']);
Route::post('/agent-runs', [AgentRunController::class, 'store']);
Route::get('/agent-runs/{id}', [AgentRunController::class, 'show']);
Route::get('/agent-runs/{id}/trace', [AgentRunController::class, 'trace']);
Route::post('/agent-runs/{id}/cancel', [AgentRunController::class, 'cancel']);
Route::post('/agent-runs/{id}/retry', [AgentRunController::class, 'retry']);
Route::post('/agent-runs/{id}/answers', [AgentRunController::class, 'answers']);

Route::get('/artifacts', [ArtifactController::class, 'index']);
Route::get('/artifacts/{id}', [ArtifactController::class, 'show']);
Route::patch('/artifacts/{id}', [ArtifactController::class, 'update']);
Route::post('/artifacts/{id}/submit', [ArtifactController::class, 'submit']);
Route::post('/artifacts/{id}/apply', [ArtifactController::class, 'apply']);

Route::get('/agent-workspaces', [AgentWorkspaceController::class, 'index']);
Route::get('/agent-workspaces/{slug}', [AgentWorkspaceController::class, 'show']);
