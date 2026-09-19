<?php

declare(strict_types=1);

use App\Http\Controllers\Launch\BusinessLaunchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Business launch — /api/launch/* (plan D7 §5, business-launch pack)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api),
| so every route here inherits that middleware. The controller additionally
| gates on the `launch.enabled` feature flag.
|
|   GET  /launch                              status, stage, next question
|   POST /launch/start           {jurisdiction?}
|   POST /launch/answer          {question_id?, answer}
|   POST /launch/stages/{stage}/commit        answers -> brain files
|   POST /launch/generate        {targets: [research|finance|plan]}
|   GET  /launch/deliverables                 model.xlsx, plan.md/docx/pdf
|   POST /launch/submit                       business_plan approval
|   GET  /launch/jurisdictions/{code}/checklist
*/

Route::prefix('launch')->name('launch.')->group(function () {
    Route::get('/', [BusinessLaunchController::class, 'show'])->name('show');
    Route::post('/start', [BusinessLaunchController::class, 'start'])->name('start');
    Route::post('/answer', [BusinessLaunchController::class, 'answer'])->name('answer');
    Route::post('/stages/{stage}/commit', [BusinessLaunchController::class, 'commitStage'])
        ->where('stage', '[a-z][a-z0-9_-]{0,31}')
        ->name('stages.commit');
    Route::post('/generate', [BusinessLaunchController::class, 'generate'])->name('generate');
    Route::get('/deliverables', [BusinessLaunchController::class, 'deliverables'])->name('deliverables');
    Route::post('/submit', [BusinessLaunchController::class, 'submit'])->name('submit');
    Route::get('/jurisdictions/{code}/checklist', [BusinessLaunchController::class, 'checklist'])
        ->where('code', '[a-z]{2,8}')
        ->name('jurisdictions.checklist');
});
