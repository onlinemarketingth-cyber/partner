<?php

use Illuminate\Support\Facades\Route;

// This backend is API-only — Blade templating is forbidden per CLAUDE.md
// Section 3. The Vue 3 SPA (in /frontend) is the only UI. This route
// exists only as a human-readable landing point for the raw domain.
Route::get('/', function () {
    return response()->json([
        'service' => config('app.name'),
        'status' => 'ok',
        'api' => '/api/v1',
    ]);
});
