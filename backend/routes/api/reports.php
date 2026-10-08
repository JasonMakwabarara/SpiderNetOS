<?php

use App\Http\Controllers\Reports\WeeklyLetterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Weekly reports — the Monday letter + the C-Suite newsletter (D8 #11, #15)
|--------------------------------------------------------------------------
| Included from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api),
| so every route here is already tenant-scoped.
*/

Route::get('/reports/newsletters', [WeeklyLetterController::class, 'index']);
Route::get('/reports/weekly', [WeeklyLetterController::class, 'show']);
Route::get('/reports/weekly/{period}', [WeeklyLetterController::class, 'show'])
    ->where('period', '[0-9]{4}-W[0-9]{2}');
