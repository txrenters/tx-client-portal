<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HelpRequestController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\InsightController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\MeetingTypeController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Admin\PropertyFinderController;
use App\Http\Controllers\Admin\ReferralController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Middleware\LogDeniedAdminAccess;
use Illuminate\Support\Facades\Route;

/*
 * Staff-only, through the "admin" gate: the owners in ADMIN_EMAILS, whom
 * nothing in the portal can change, plus staff and admins they grant on the
 * Staff page. Every write here
 * acts on a portal account and is recoverable, is written to the admin audit
 * trail (AdminAudit), and none of them touch Propertyware.
 */
Route::middleware(['auth', 'verified', LogDeniedAdminAccess::class, 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/dashboard');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('activity', ActivityController::class)->name('activity');
    Route::get('insights', InsightController::class)->name('insights');
    Route::get('properties', PropertyFinderController::class)->middleware('throttle:30,1')->name('properties');

    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::post('invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1')->name('invitations.store');
    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])
        ->middleware('throttle:20,1')->name('invitations.resend');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');

    Route::get('meetings', [MeetingTypeController::class, 'index'])->name('meetings.index');
    Route::put('meetings', [MeetingTypeController::class, 'update'])->name('meetings.update');

    Route::resource('announcements', AnnouncementController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('announcements/{announcement}/end', [AnnouncementController::class, 'end'])->name('announcements.end');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::post('users/{user}/verification', [UserController::class, 'resendVerification'])
        ->middleware('throttle:10,1')->name('users.verification.resend');
    Route::post('users/{user}/mark-verified', [UserController::class, 'markVerified'])
        ->middleware('throttle:20,1')->name('users.mark-verified');
    Route::post('users/{user}/two-factor-reset', [UserController::class, 'resetTwoFactor'])
        ->middleware('throttle:10,1')->name('users.two-factor-reset');
    Route::post('users/{user}/suspend', [UserController::class, 'suspend'])
        ->middleware('throttle:20,1')->name('users.suspend');
    Route::post('users/{user}/restore', [UserController::class, 'restore'])
        ->middleware('throttle:20,1')->name('users.restore');
    Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])
        ->middleware('throttle:10,1')->name('users.password-reset');
    Route::post('users/{user}/revoke-app-sessions', [UserController::class, 'revokeAppSessions'])
        ->middleware('throttle:20,1')->name('users.revoke-app-sessions');
    Route::post('users/{user}/relink-accounts', [UserController::class, 'relinkAccounts'])
        ->middleware('throttle:20,1')->name('users.relink-accounts');

    Route::post('users/{user}/impersonate', [ImpersonationController::class, 'store'])
        ->middleware('throttle:10,1')->name('users.impersonate');

    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
    Route::put('referrals/reward', [ReferralController::class, 'updateReward'])->name('referrals.reward');
    Route::patch('referrals/{referral}', [ReferralController::class, 'update'])->name('referrals.update');
    Route::post('referrals/{referral}/resend-lead', [ReferralController::class, 'resendLead'])
        ->middleware('throttle:20,1')->name('referrals.resend-lead');

    Route::get('help-requests', [HelpRequestController::class, 'index'])->name('help-requests.index');
    Route::patch('help-requests/{helpRequest}', [HelpRequestController::class, 'update'])->name('help-requests.update');

    Route::resource('promotions', PromotionController::class)->only(['index', 'store', 'update', 'destroy']);

    // Who else is staff: owners and admins only, behind a fresh password.
    Route::middleware(['can:manage-staff', 'password.confirm'])->group(function () {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->middleware('throttle:20,1')->name('staff.store');
        Route::patch('staff/{user}', [StaffController::class, 'update'])->name('staff.update');
        Route::delete('staff/{user}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });

    Route::get('security', [SecurityController::class, 'index'])->name('security');
    Route::post('security/blocks', [SecurityController::class, 'store'])->middleware('throttle:30,1')->name('security.blocks.store');
    Route::delete('security/blocks/{block}', [SecurityController::class, 'destroy'])->name('security.blocks.destroy');

    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::post('integrations/refresh', [IntegrationController::class, 'refresh'])
        ->middleware('throttle:20,1')->name('integrations.refresh');
    Route::post('integrations/failed-jobs/{uuid}/retry', [IntegrationController::class, 'retryJob'])
        ->middleware('throttle:30,1')->name('integrations.failed-jobs.retry');
    Route::delete('integrations/failed-jobs/{uuid}', [IntegrationController::class, 'forgetJob'])
        ->middleware('throttle:30,1')->name('integrations.failed-jobs.forget');
});

/*
 * Ending an impersonation is done by the session that is being impersonated,
 * so it cannot sit behind the admin gate: by then the signed-in user is the
 * client. The session marker is the authorisation.
 */
Route::delete('impersonate', [ImpersonationController::class, 'destroy'])
    ->middleware('auth')
    ->name('impersonate.stop');
