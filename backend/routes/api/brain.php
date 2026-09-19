<?php

use App\Http\Controllers\Brain\BrainController;
use App\Http\Controllers\ZetKai\ZetKaiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Knowledge brain — /api/brain/* (ADR-0002 D2)
|--------------------------------------------------------------------------
| Required by routes/api.php inside the protected tenant group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api),
| so every handler already has the resolved tenant on the request.
|
| {path} is a slash-separated brain path (business/profile.md); the
| versions/revert routes are declared before the generic file routes so
| `.../versions` is never swallowed by the `.*` path constraint.
*/

Route::prefix('brain')->name('brain.')->group(function () {
    // ZetKai -> the Knowledge brain (plan D7 §4). Reading the status is not
    // privileged; pulling someone's personal vault on demand is.
    Route::get('/zetkai/status', [ZetKaiController::class, 'status'])->name('zetkai.status');
    Route::post('/zetkai/sync-now', [ZetKaiController::class, 'syncNow'])->middleware('role:admin')->name('zetkai.sync');

    Route::get('/tree', [BrainController::class, 'tree'])->name('tree');
    Route::get('/readiness', [BrainController::class, 'readiness'])->name('readiness');
    Route::get('/gaps', [BrainController::class, 'gaps'])->name('gaps');
    Route::get('/search', [BrainController::class, 'search'])->name('search');
    Route::get('/proposals', [BrainController::class, 'proposals'])->name('proposals');
    Route::post('/sync', [BrainController::class, 'sync'])->name('sync');

    Route::get('/files/{path}/versions', [BrainController::class, 'versions'])->where('path', '.*')->name('files.versions');
    Route::post('/files/{path}/revert', [BrainController::class, 'revert'])->where('path', '.*')->name('files.revert');

    Route::get('/files/{path}', [BrainController::class, 'show'])->where('path', '.*')->name('files.show');
    Route::put('/files/{path}', [BrainController::class, 'update'])->where('path', '.*')->name('files.update');
    Route::delete('/files/{path}', [BrainController::class, 'destroy'])->where('path', '.*')->name('files.destroy');
});
