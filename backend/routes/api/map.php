<?php

declare(strict_types=1);

use App\Http\Controllers\Map\BusinessMapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Business map — /api/map/* (plan D6-C, ADR-0002)
|--------------------------------------------------------------------------
|
| Required from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api),
| so every route here inherits that middleware.
|
|   GET /map               core (three brains) + nine pillars + nodes
|   GET /map/nodes/{id}    node + processes[] + runs[] + brain_files[]
|                          {id} = skill node slug or business system uuid
*/

Route::prefix('map')->name('map.')->group(function () {
    Route::get('/', [BusinessMapController::class, 'index'])->name('index');
    Route::get('/nodes/{id}', [BusinessMapController::class, 'node'])
        ->where('id', '[A-Za-z0-9][A-Za-z0-9-]{0,127}')
        ->name('nodes.show');
});
