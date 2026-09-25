<?php

declare(strict_types=1);

use App\Http\Controllers\Agents\SkillRunController;
use App\Http\Controllers\Skills\SkillsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Skills catalogue — /api/skills/* (plan D5, ADR-0002)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api),
| so every route here inherits that middleware.
|
|   GET  /skills                    catalogue merged with tenant state + brain readiness
|   GET  /skills/{slug}             the full-screen card JSON
|   POST /skills/{slug}/enable      "provided when needed": tenant_skills + agents + workspace
|   PUT  /skills/{slug}/pipeline    {stage: human_led|assisted|autonomous}
|   POST /skills/{slug}/feedback    {sentiment, outcome}
|   POST /skills/signals            {type, slug} fire-and-forget
|   POST /skills/{slug}/run         → Agents\SkillRunController::run (runtime stream, PR 1 B2)
*/

Route::prefix('skills')->name('skills.')->group(function () {
    Route::get('/', [SkillsController::class, 'index'])->name('index');
    Route::post('/signals', [SkillsController::class, 'signals'])->name('signals');

    Route::get('/{slug}', [SkillsController::class, 'show'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('show');
    Route::post('/{slug}/enable', [SkillsController::class, 'enable'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('enable');
    Route::put('/{slug}/pipeline', [SkillsController::class, 'pipeline'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('pipeline');
    Route::post('/{slug}/feedback', [SkillsController::class, 'feedback'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('feedback');

    // The run endpoint belongs to the runtime stream; the controller class is
    // resolved lazily at dispatch, so this file loads before it lands.
    Route::post('/{slug}/run', [SkillRunController::class, 'run'])
        ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
        ->name('run');
});
