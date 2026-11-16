<?php

// API JobDating. Préfixe : /api/v1 (bootstrap/app.php).
// Authentification : session par cookie Sanctum + CSRF, sans jeton (CDC 9.5).

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class)
    ->middleware('throttle:login')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'session.valid'])->group(function () {
    Route::post('/logout', LogoutController::class)->name('auth.logout');
    Route::get('/me', MeController::class)->name('auth.me');
});
