<?php

// API JobDating. Préfixe : /api/v1 (bootstrap/app.php).
// Authentification : session par cookie Sanctum + CSRF, sans jeton (CDC 9.5).

use App\Enums\Permission;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\ReferentialController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class)
    ->middleware('throttle:login')
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'session.valid'])->group(function () {
    Route::post('/logout', LogoutController::class)->name('auth.logout');
    Route::get('/me', MeController::class)->name('auth.me');

    // Journal d'audit (TRA-01) : administrateur uniquement (CDC 3.2).
    Route::middleware('permission:'.Permission::AuditView->value)->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
        Route::get('/audit-logs/filters', [AuditLogController::class, 'filters'])->name('audit-logs.filters');
    });

    // Référentiels (ADM-09). Lecture des options : tout utilisateur connecté.
    Route::get('/referentials/{type}/options', [ReferentialController::class, 'options'])->name('referentials.options');

    // Gestion : administrateur et équipe CME (CDC 3.2). Aucune suppression (décision du 16/11/2026).
    Route::middleware('permission:'.Permission::ReferentialsManage->value)->group(function () {
        Route::get('/referentials/{type}', [ReferentialController::class, 'index'])->name('referentials.index');
        Route::post('/referentials/{type}', [ReferentialController::class, 'store'])->name('referentials.store');
        Route::put('/referentials/{type}/order', [ReferentialController::class, 'order'])->name('referentials.order');
        Route::patch('/referentials/{type}/{id}', [ReferentialController::class, 'update'])->whereNumber('id')->name('referentials.update');
    });
});
