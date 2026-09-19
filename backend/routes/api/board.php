<?php

use App\Http\Controllers\Board\BoardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Board of advisors (plan D6 §6)
|--------------------------------------------------------------------------
| Included from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api),
| so every route here is already tenant-scoped. Each one is additionally
| gated by the board.enabled flag inside the controller.
*/

Route::get('/board/seats', [BoardController::class, 'seats']);
Route::get('/board/sessions', [BoardController::class, 'index']);
Route::post('/board/sessions', [BoardController::class, 'store']);
Route::get('/board/sessions/{id}', [BoardController::class, 'show']);
Route::get('/board/sessions/{id}/report.md', [BoardController::class, 'report']);
