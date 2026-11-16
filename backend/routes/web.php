<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    $payload = [
        'name' => 'SpiderNet OS',
        'version' => '3.2.0',
        'status' => 'operational',
        'planes' => [
            'control' => 'http://127.0.0.1:8000',
            'inference' => 'http://localhost:9000',
            'cockpit' => 'http://localhost:3000/cockpit/',
        ],
    ];

    if (! str_contains((string) $request->header('Accept'), 'text/html')) {
        return response()->json($payload);
    }

    $cockpit = $payload['planes']['cockpit'];

    return response(
        '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>SpiderNet OS</title></head>'
        .'<body style="font-family: sans-serif; margin: 3rem auto; max-width: 36rem; line-height: 1.5;">'
        .'<h1>SpiderNet OS</h1>'
        .'<p>The API is operational. This address serves the API, not the application.</p>'
        .'<p><a href="'.e($cockpit).'">Open the cockpit</a></p>'
        .'</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=UTF-8']
    );
});
require __DIR__.'/health.php';
