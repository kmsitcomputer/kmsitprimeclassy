<?php

use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// IMP-001: Google sign-in. Must live under /api so the production bridge (public_html/laravel.php
// routes /api, /sanctum, /up to Laravel) reaches it; it needs the `web` group for the session-backed
// OAuth state, which the `api` group does not provide for Google's cross-site callback.
Route::prefix('api/v1/auth/google')->middleware('throttle:20,1')->group(function () {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('/callback', [GoogleAuthController::class, 'callback']);
});
