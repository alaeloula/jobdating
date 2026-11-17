<?php

// API JobDating. Préfixe : /api/v1 (bootstrap/app.php).
// Authentification : session par cookie Sanctum + CSRF, sans jeton (CDC 9.5).

use App\Enums\Permission;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\ActivationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordLinkRequestController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\ReferentialController;
use Illuminate\Support\Facades\Route;

Route::post('/login', LoginController::class)
    ->middleware('throttle:login')
    ->name('auth.login');

// Mots de passe (T-05, AUTH-02 / AUTH-03). Routes publiques ; jamais de jeton dans une adresse.
Route::middleware('no.store')->group(function () {
    // Demandes de lien : même réponse que le compte existe ou non ; limites dans le contrôleur
    // (1 / min par adresse, 10 / min par IP, budget commun).
    Route::post('/password/forgot', [PasswordLinkRequestController::class, 'forgot'])->name('password.forgot');
    Route::post('/activation/resend', [PasswordLinkRequestController::class, 'resendActivation'])->name('activation.resend');

    Route::middleware('throttle:password-token')->group(function () {
        Route::post('/activation/check', [ActivationController::class, 'check'])->name('activation.check');
        Route::post('/activation', [ActivationController::class, 'activate'])->name('activation.activate');
        Route::post('/password/reset/check', [PasswordResetController::class, 'check'])->name('password.reset.check');
        Route::post('/password/reset', [PasswordResetController::class, 'reset'])->name('password.reset');
    });
});

Route::middleware(['auth:sanctum', 'session.valid'])->group(function () {
    Route::post('/logout', LogoutController::class)->name('auth.logout');
    Route::get('/me', MeController::class)->name('auth.me');

    // Changement de son mot de passe (AUTH-03) : les 5 rôles.
    Route::put('/me/password', PasswordChangeController::class)
        ->middleware(['no.store', 'throttle:password-change'])
        ->name('auth.password.change');

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
