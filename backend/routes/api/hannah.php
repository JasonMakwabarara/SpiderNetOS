<?php

use App\Http\Controllers\Hannah\HannahHandoffController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Hannah AI hand-off (plan D7 §1) — "I need to market this new product"
|--------------------------------------------------------------------------
| Included from routes/api.php inside the protected group
| (auth:sanctum + tenant + onboarding.required + cost.limit + throttle:api).
|
| `hannah_ai` is the external product at hannah-ai.world. The `hannah`
| character inside SpiderNetOS is unrelated and shares no namespace with it.
|
| Linking creates an account on a third-party product in the owner's name, so
| the write routes are admin-only; reading the status is not.
*/

Route::get('/hannah/link', [HannahHandoffController::class, 'show']);
Route::get('/hannah/deep-link', [HannahHandoffController::class, 'deepLink']);

Route::middleware('role:admin')->group(function () {
    Route::post('/hannah/link', [HannahHandoffController::class, 'link']);
    Route::post('/hannah/brand/sync', [HannahHandoffController::class, 'syncBrand']);
});
