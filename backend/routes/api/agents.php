<?php

use App\Http\Controllers\Agents\AgentRunController;
use App\Http\Controllers\Agents\AgentWorkspaceController;
use App\Http\Controllers\Agents\ArtifactController;
use App\Http\Controllers\Agents\GodsEyeController;
use App\Http\Controllers\Agents\ResearchBriefController;
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
// Viewers read; editing needs a member, and editing content under review
// needs an approver (ArtifactController::update).
Route::patch('/artifacts/{id}', [ArtifactController::class, 'update'])->middleware('role:member');
Route::post('/artifacts/{id}/submit', [ArtifactController::class, 'submit']);
// Applying makes an approved artifact real: the approver's capability.
Route::post('/artifacts/{id}/apply', [ArtifactController::class, 'apply'])->middleware('can.do:approvals.decide');

// Research briefs are agent artifacts of kind research_brief. The body
// cannot choose a tenant or an approval state. Submit stays on
// POST /artifacts/{id}/submit so the approval resource stays one path.
Route::get('/research-briefs', [ResearchBriefController::class, 'index']);
Route::post('/research-briefs', [ResearchBriefController::class, 'store']);
Route::get('/research-briefs/{id}/markdown', [ResearchBriefController::class, 'markdown']);
Route::get('/research-briefs/{id}', [ResearchBriefController::class, 'show']);

Route::get('/agent-workspaces', [AgentWorkspaceController::class, 'index']);
Route::get('/agent-workspaces/{slug}', [AgentWorkspaceController::class, 'show']);

// God's Eye (plan D6 §8) — the whole wall in one call, because a board the
// client has to stitch together from six endpoints is a board that shows six
// different moments.
Route::get('/godseye/snapshot', [GodsEyeController::class, 'snapshot']);
