<?php

use Illuminate\Support\Facades\Route;

// This service only answers API routes; the dashboard is a separate Next app.
Route::get('/', fn () => response()->json([
    'service' => 'fusion-tester-api',
    'message' => 'API only. See /health.',
]));
