<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'SpiderNet OS',
        'version' => '3.2.0',
        'status' => 'operational',
        'planes' => [
            'control' => 'http://localhost:8000',
            'inference' => 'http://localhost:9000',
            'cockpit' => 'http://localhost:5173',
        ],
    ]);
});
