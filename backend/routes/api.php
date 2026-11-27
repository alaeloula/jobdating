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
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyImportController;
use App\Http\Controllers\CvController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventParticipantController;
use App\Http\Controllers\LearnerController;
use App\Http\Controllers\LearnerImportController;
use App\Http\Controllers\MyCompanyController;
use App\Http\Controllers\MyEventController;
use App\Http\Controllers\ReferentialController;
use App\Http\Controllers\StaffAccountController;
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

    // Comptes de l'équipe : CME, Direction, administrateur (ADM-01, T-10). Administrateur seul.
    Route::middleware('permission:'.Permission::UsersManage->value)->group(function () {
        Route::get('/users', [StaffAccountController::class, 'index'])->name('users.index');
        Route::post('/users', [StaffAccountController::class, 'store'])->name('users.store');
        Route::patch('/users/{id}', [StaffAccountController::class, 'update'])->whereNumber('id')->name('users.update');
        Route::delete('/users/{id}', [StaffAccountController::class, 'destroy'])->whereNumber('id')->name('users.destroy');
        Route::post('/users/{id}/deactivate', [StaffAccountController::class, 'deactivate'])->whereNumber('id')->name('users.deactivate');
        Route::post('/users/{id}/reactivate', [StaffAccountController::class, 'reactivate'])->whereNumber('id')->name('users.reactivate');
        Route::post('/users/{id}/restore', [StaffAccountController::class, 'restore'])->whereNumber('id')->name('users.restore');
        Route::post('/users/{id}/resend-activation', [StaffAccountController::class, 'resendActivation'])->whereNumber('id')->name('users.resend-activation');
        Route::post('/users/{id}/reset-password', [StaffAccountController::class, 'resetPassword'])->whereNumber('id')
            ->middleware('no.store')->name('users.reset-password');
    });

    // Entreprises (ADM-02, T-11). Liste, fiche et profils recherchés : administrateur et CME ;
    // gestion du compte et des identifiants : administrateur.
    $manage = 'permission:'.Permission::CompaniesManage->value;
    $read = 'permission:'.Permission::CompaniesManage->value.'|'.Permission::CompaniesProfilesManage->value;

    Route::get('/companies', [CompanyController::class, 'index'])->middleware($read)->name('companies.index');
    Route::get('/companies/username-suggestion', [CompanyController::class, 'usernameSuggestion'])->middleware($manage)->name('companies.username-suggestion');
    Route::get('/companies/{id}', [CompanyController::class, 'show'])->whereNumber('id')->middleware($read)->name('companies.show');
    Route::put('/companies/{id}/profiles', [CompanyController::class, 'profiles'])->whereNumber('id')
        ->middleware('permission:'.Permission::CompaniesProfilesManage->value)->name('companies.profiles');
    Route::get('/companies/{id}/logo', [CompanyController::class, 'logo'])->whereNumber('id')
        ->middleware('permission:'.Permission::CompaniesView->value.'|'.Permission::CompaniesManage->value)->name('companies.logo');

    Route::middleware($manage)->group(function () {
        Route::post('/companies', [CompanyController::class, 'store'])->middleware('no.store')->name('companies.store');
        Route::patch('/companies/{id}', [CompanyController::class, 'update'])->whereNumber('id')->name('companies.update');
        Route::post('/companies/{id}/logo', [CompanyController::class, 'storeLogo'])->whereNumber('id')->name('companies.logo.store');
        Route::delete('/companies/{id}/logo', [CompanyController::class, 'destroyLogo'])->whereNumber('id')->name('companies.logo.destroy');
        Route::post('/companies/{id}/deactivate', [CompanyController::class, 'deactivate'])->whereNumber('id')->name('companies.deactivate');
        Route::post('/companies/{id}/reactivate', [CompanyController::class, 'reactivate'])->whereNumber('id')->name('companies.reactivate');
        Route::post('/companies/{id}/reset-password', [CompanyController::class, 'resetPassword'])->whereNumber('id')
            ->middleware('no.store')->name('companies.reset-password');
        Route::post('/companies/{id}/credentials-printed', [CompanyController::class, 'credentialsPrinted'])->whereNumber('id')
            ->name('companies.credentials-printed');

        // Import des entreprises (ADM-04, T-64) : administrateur.
        Route::get('/company-imports/expected', [CompanyImportController::class, 'expected'])->name('company-imports.expected');
        Route::post('/company-imports/preview', [CompanyImportController::class, 'preview'])->name('company-imports.preview');
        Route::post('/company-imports', [CompanyImportController::class, 'store'])->name('company-imports.store');
        Route::get('/company-imports', [CompanyImportController::class, 'index'])->name('company-imports.index');
        Route::get('/company-imports/{id}', [CompanyImportController::class, 'show'])->whereNumber('id')->name('company-imports.show');
        Route::get('/company-imports/{id}/credentials', [CompanyImportController::class, 'credentials'])->whereNumber('id')
            ->middleware('no.store')->name('company-imports.credentials');
        Route::post('/company-imports/{id}/credentials-printed', [CompanyImportController::class, 'credentialsPrinted'])->whereNumber('id')
            ->name('company-imports.credentials-printed');
        Route::delete('/company-imports/{id}/credentials', [CompanyImportController::class, 'destroyCredentials'])->whereNumber('id')
            ->name('company-imports.credentials.destroy');
    });

    // Événements (ADM-05, T-14) : administrateur.
    Route::middleware('permission:'.Permission::EventsManage->value)->group(function () {
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::post('/events', [EventController::class, 'store'])->name('events.store');
        Route::delete('/events/current', [EventController::class, 'unsetCurrent'])->name('events.current.unset');
        Route::get('/events/{id}', [EventController::class, 'show'])->whereNumber('id')->name('events.show');
        Route::patch('/events/{id}', [EventController::class, 'update'])->whereNumber('id')->name('events.update');
        Route::delete('/events/{id}', [EventController::class, 'destroy'])->whereNumber('id')->name('events.destroy');
        Route::post('/events/{id}/current', [EventController::class, 'setCurrent'])->whereNumber('id')->name('events.current.set');
        Route::post('/events/{id}/archive', [EventController::class, 'archive'])->whereNumber('id')->name('events.archive');
        Route::post('/events/{id}/unarchive', [EventController::class, 'unarchive'])->whereNumber('id')->name('events.unarchive');
    });
    Route::get('/event-options', [EventController::class, 'options'])->middleware($manage)->name('events.options');
    Route::get('/events/current/summary', [EventController::class, 'currentSummary'])
        ->middleware('permission:'.Permission::LocationsManage->value)->name('events.current.summary');

    // Participants (ADM-07, T-14) : ajouts et retraits par l'administrateur ; emplacements aussi par la CME.
    Route::get('/events/{id}/companies', [EventParticipantController::class, 'companies'])->whereNumber('id')
        ->middleware('permission:'.Permission::ParticipantsManage->value.'|'.Permission::LocationsManage->value)->name('events.companies');
    Route::patch('/events/{id}/companies/{company}/location', [EventParticipantController::class, 'updateLocation'])
        ->whereNumber(['id', 'company'])->middleware('permission:'.Permission::LocationsManage->value)->name('events.companies.location');
    Route::middleware('permission:'.Permission::ParticipantsManage->value)->group(function () {
        Route::get('/events/{id}/available-companies', [EventParticipantController::class, 'availableCompanies'])->whereNumber('id')->name('events.companies.available');
        Route::post('/events/{id}/companies', [EventParticipantController::class, 'addCompanies'])->whereNumber('id')->name('events.companies.add');
        Route::delete('/events/{id}/companies/{company}', [EventParticipantController::class, 'removeCompany'])->whereNumber(['id', 'company'])->name('events.companies.remove');
        Route::delete('/events/{id}/learners/{learner}', [EventParticipantController::class, 'removeLearner'])->whereNumber(['id', 'learner'])->name('events.learners.remove');
        Route::post('/events/{id}/learners/{learner}/reinstate', [EventParticipantController::class, 'reinstateLearner'])->whereNumber(['id', 'learner'])->name('events.learners.reinstate');
    });

    // Événement du compte connecté (ENT-01, APP-00) : le courant, pour son propriétaire uniquement.
    Route::get('/me/company-event', [MyEventController::class, 'company'])->name('me.company-event');
    Route::get('/me/learner-event', [MyEventController::class, 'learner'])->name('me.learner-event');

    // Apprenants (ADM-03, T-12). Limités aux RÔLES administrateur, CME et Direction : l'entreprise
    // a `learners.view` pour T-23 (participants de ses événements) et n'accède jamais à ces routes.
    Route::middleware(['role:administrateur|cme|direction', 'permission:'.Permission::LearnersView->value])->group(function () {
        Route::post('/learners/search', [LearnerController::class, 'search'])->name('learners.search');
        Route::get('/learners/{id}', [LearnerController::class, 'show'])->whereNumber('id')->name('learners.show');

        Route::middleware('permission:'.Permission::LearnersCompetencesManage->value)->group(function () {
            Route::post('/learners/selection/count', [LearnerController::class, 'selectionCount'])->name('learners.selection.count');
            Route::put('/learners/{id}/competences', [LearnerController::class, 'competences'])->whereNumber('id')->name('learners.competences');
            Route::post('/learners/bulk-competences', [LearnerController::class, 'bulkCompetences'])->name('learners.bulk-competences');
        });

        Route::middleware('permission:'.Permission::LearnersUpdate->value)->group(function () {
            Route::post('/learners', [LearnerController::class, 'store'])->middleware('no.store')->name('learners.store');
            Route::put('/learners/{id}', [LearnerController::class, 'update'])->whereNumber('id')->name('learners.update');
            Route::post('/learners/{id}/reset-password', [LearnerController::class, 'resetPassword'])->whereNumber('id')
                ->middleware('no.store')->name('learners.reset-password');
            Route::post('/learners/{id}/deactivate', [LearnerController::class, 'deactivate'])->whereNumber('id')->name('learners.deactivate');
            Route::post('/learners/{id}/reactivate', [LearnerController::class, 'reactivate'])->whereNumber('id')->name('learners.reactivate');
            Route::delete('/learners/{id}', [LearnerController::class, 'destroy'])->whereNumber('id')->name('learners.destroy');
            Route::post('/learners/{id}/restore', [LearnerController::class, 'restore'])->whereNumber('id')->name('learners.restore');
            Route::post('/learners/bulk-deactivate', [LearnerController::class, 'bulkDeactivate'])->name('learners.bulk-deactivate');
        });

        // CV (CV-01, CV-04, T-20) : administrateur et CME. La Direction voit l'état dans la fiche, sans action.
        Route::middleware('permission:'.Permission::CvUpload->value)->group(function () {
            Route::post('/learners/{id}/cv', [CvController::class, 'upload'])->whereNumber('id')->name('learners.cv.upload');
            Route::delete('/learners/{id}/cv', [CvController::class, 'remove'])->whereNumber('id')->name('learners.cv.remove');
        });
        Route::get('/learners/{id}/cv/history', [CvController::class, 'history'])->whereNumber('id')
            ->middleware('permission:'.Permission::CvHistory->value)->name('learners.cv.history');
    });

    // Import des apprenants (ADM-04, T-13) : permission `learners.import` seule (décision 13 du 20/11).
    Route::middleware('permission:'.Permission::LearnersImport->value)->group(function () {
        Route::post('/learner-imports/preview', [LearnerImportController::class, 'preview'])->name('learner-imports.preview');
        Route::post('/learner-imports', [LearnerImportController::class, 'store'])->name('learner-imports.store');
        Route::get('/learner-imports', [LearnerImportController::class, 'index'])->name('learner-imports.index');
        Route::get('/learner-imports/{id}', [LearnerImportController::class, 'show'])->whereNumber('id')->name('learner-imports.show');
        Route::get('/learner-imports/{id}/passwords', [LearnerImportController::class, 'passwords'])->whereNumber('id')
            ->middleware('no.store')->name('learner-imports.passwords');
        Route::delete('/learner-imports/{id}/passwords', [LearnerImportController::class, 'destroyPasswords'])->whereNumber('id')
            ->name('learner-imports.passwords.destroy');
    });

    // Fiche de l'entreprise connectée (ENT-08) : description et site web.
    Route::get('/me/company', [MyCompanyController::class, 'show'])->name('me.company');
    Route::put('/me/company', [MyCompanyController::class, 'update'])->name('me.company.update');

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
