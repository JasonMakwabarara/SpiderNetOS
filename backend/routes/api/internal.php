<?php

use App\Http\Controllers\Agents\InternalBrainFileController;
use App\Http\Controllers\Agents\InternalToolController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Backend-internal tool gateway + brain reads (ADR-0002, plan D4)
|--------------------------------------------------------------------------
| Required from the `internal` group in routes/api.php (X-Internal-Key via
| the internal.key middleware; tenant scope via X-Tenant-Id). Used by the
| Python intelligence plane and intelligence/mcp_server.py.
*/

Route::get('/tools/schema', [InternalToolController::class, 'schema']);
Route::post('/tools/{name}/execute', [InternalToolController::class, 'execute']);
Route::get('/brain/files/{path}', [InternalBrainFileController::class, 'show'])->where('path', '.*');
