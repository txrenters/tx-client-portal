<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Staff-only. Admin is an allowlist of email addresses in config, checked by
 * the "admin" gate, so nothing in the portal can grant it. Every write here
 * acts on a portal account and is recoverable; none of them touch Propertyware.
 */
Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('activity', ActivityController::class)->name('activity');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
        ->middleware('throttle:10,1')->name('users.password-reset');
    Route::post('users/{user}/revoke-app-sessions', [UserController::class, 'revokeAppSessions'])
        ->middleware('throttle:20,1')->name('users.revoke-app-sessions');
    Route::post('users/{user}/relink-accounts', [UserController::class, 'relinkAccounts'])
        ->middleware('throttle:20,1')->name('users.relink-accounts');

    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store'])
        ->middleware('throttle:10,1')->name('users.impersonate');

    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('integrations/refresh', [IntegrationController::class, 'refresh'])
        ->middleware('throttle:20,1')->name('integrations.refresh');
});

/*
 * Ending an impersonation is done by the session that is being impersonated,
 * so it cannot sit behind the admin gate: by then the signed-in user is the
 * client. The session marker is the authorisation.
 */
Route::delete('impersonate', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('impersonate.stop');
