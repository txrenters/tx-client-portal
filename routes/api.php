<?php

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactDetailsController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\FinancialController;
use App\Http\Controllers\Api\V1\HelpRequestController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\LeaseController;
use App\Http\Controllers\Api\V1\LedgerDownloadController;
use App\Http\Controllers\Api\V1\MaintenanceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OwnerIncomeStatementController;
use App\Http\Controllers\Api\V1\OwnerLedgerController;
use App\Http\Controllers\Api\V1\OwnerStatementController;
use App\Http\Controllers\Api\V1\PasskeyController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PortfolioController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PropertyController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\TwoFactorAuthenticationController;
use App\Http\Controllers\HubEventController;
use App\Http\Controllers\SchedulingEventController;
use App\Services\Promotions\PromotionBoard;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
 * Version 1 of the API used by the iOS and Android apps.
 *
 * Apps authenticate with a Sanctum token, then read one linked Propertyware
 * account at a time under /accounts/{account}.
 */
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:6,1')->prefix('auth')->name('auth.')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');
    });

    Route::middleware('throttle:10,1')->prefix('auth/passkeys')->name('auth.passkeys.')->group(function () {
        Route::post('options', [PasskeyController::class, 'loginOptions'])->name('options');
        Route::post('login', [PasskeyController::class, 'login'])->name('login');
    });

    // Signed events from the messaging hub (tx-chatbot); checked in the controller, not by login.
    Route::post('hub/events', HubEventController::class)->middleware('throttle:600,1')->name('hub.events');
    // Signed booking events from tx-scheduling; also checked in the controller.
    Route::post('scheduling/events', SchedulingEventController::class)->middleware('throttle:600,1')->name('scheduling.events');

    Route::get('invitations/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1')->name('invitations.show');
    Route::post('invitations/{token}', [InvitationController::class, 'store'])->middleware('throttle:6,1')->name('invitations.store');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::get('me', MeController::class)->name('me');
        Route::patch('me', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('me', [ProfileController::class, 'destroy'])->name('profile.destroy');
        Route::put('me/password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
        Route::get('me/notifications', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
        Route::put('me/notifications', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');
        // Not behind "verified": the app registers the phone as soon as it has
        // a session, and an unverified client still gets password and security
        // notifications worth ringing for.
        Route::post('me/push-tokens', [PushTokenController::class, 'store'])->middleware('throttle:30,1')->name('push-tokens.store');
        Route::delete('me/push-tokens', [PushTokenController::class, 'destroy'])->middleware('throttle:30,1')->name('push-tokens.destroy');
        // Also not behind "verified", for the same reason as the web Help page.
        Route::get('me/help-requests', [HelpRequestController::class, 'index'])->name('help-requests.index');
        Route::post('me/help-requests', [HelpRequestController::class, 'store'])->middleware('throttle:5,1')->name('help-requests.store');
        Route::get('me/help-requests/attachments/{attachment}', [HelpRequestController::class, 'attachment'])->name('help-requests.attachments.show');

        Route::middleware('verified')->group(function () {
            Route::get('me/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
            Route::post('me/announcements/{announcement}/dismiss', [AnnouncementController::class, 'dismiss'])->name('announcements.dismiss');
            Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

            Route::prefix('me/passkeys')->name('passkeys.')->group(function () {
                Route::get('/', [PasskeyController::class, 'index'])->name('index');
                Route::post('options', [PasskeyController::class, 'registrationOptions'])->middleware('throttle:6,1')->name('options');
                Route::post('/', [PasskeyController::class, 'store'])->middleware('throttle:6,1')->name('store');
                Route::delete('{passkey}', [PasskeyController::class, 'destroy'])->name('destroy');
            });

            Route::middleware('throttle:6,1')->prefix('me/two-factor')->name('two-factor.')->group(function () {
                Route::post('/', [TwoFactorAuthenticationController::class, 'store'])->name('store');
                Route::post('confirm', [TwoFactorAuthenticationController::class, 'confirm'])->name('confirm');
                Route::post('recovery-codes', [TwoFactorAuthenticationController::class, 'regenerateRecoveryCodes'])->name('recovery-codes');
                Route::delete('/', [TwoFactorAuthenticationController::class, 'destroy'])->name('destroy');
            });
        });

        Route::middleware(['verified', 'can:view,account'])->prefix('accounts/{account}')->name('accounts.')->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');
            Route::get('portfolio', PortfolioController::class)->name('portfolio');
            Route::get('lease', LeaseController::class)->name('lease');
            Route::get('payments', PaymentController::class)->name('payments');
            Route::get('payments/download', LedgerDownloadController::class)->middleware('throttle:10,1')->name('payments.download');
            Route::get('financials', FinancialController::class)->name('financials');
            Route::get('ledger', OwnerLedgerController::class)->name('ledger');
            Route::get('statement', OwnerStatementController::class)->name('statement');
            Route::get('income-statement', [OwnerIncomeStatementController::class, 'show'])->name('income-statement');
            Route::get('income-statement/pdf', [OwnerIncomeStatementController::class, 'pdf'])->middleware('throttle:10,1')->name('income-statement.pdf');
            Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
            Route::get('properties/{property}', [PropertyController::class, 'show'])->whereNumber('property')->name('properties.show');
            Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
            Route::get('approvals', ApprovalController::class)->name('approvals.index');
            Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
            Route::get('contact-details', ContactDetailsController::class)->name('contact-details');
            Route::get('messages', [MessageController::class, 'index'])->name('messages.index');
            Route::get('messages/history', [MessageController::class, 'history'])->name('messages.history');
            Route::get('messages/earlier-media/{media}/{name}', [MessageController::class, 'earlierMedia'])->whereNumber('media')->name('messages.earlier-media');
            Route::get('realtime', [MessageController::class, 'realtime'])->name('realtime');
            Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
            Route::get('promotion', [PromotionController::class, 'show'])->name('promotion.show');
            Route::post('promotions/{promotion}/{action}', [PromotionController::class, 'record'])
                ->whereIn('action', PromotionBoard::ACTIONS)->middleware('throttle:60,1')->name('promotions.record');
            Route::post('referrals', [ReferralController::class, 'store'])->middleware('throttle:10,1')->name('referrals.store');
            Route::post('messages', [MessageController::class, 'store'])->middleware('throttle:10,1')->name('messages.store');
            Route::get('messages/{thread}', [MessageController::class, 'show'])->whereNumber('thread')->name('messages.show');
            Route::post('messages/{thread}/replies', [MessageController::class, 'reply'])->whereNumber('thread')->middleware('throttle:30,1')->name('messages.reply');
            Route::get('conversations', ConversationController::class)->name('conversations.index');
            Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
            Route::get('maintenance/request-options', [MaintenanceController::class, 'requestOptions'])->name('maintenance.request-options');
            Route::post('maintenance', [MaintenanceController::class, 'store'])->middleware('throttle:10,1')->name('maintenance.store');
            Route::get('maintenance/{workOrder}', [MaintenanceController::class, 'show'])->name('maintenance.show');
            Route::post('maintenance/{workOrder}/approval', [MaintenanceController::class, 'decide'])->middleware('throttle:10,1')->name('maintenance.approval');
            Route::get('maintenance/{workOrder}/documents', [MaintenanceController::class, 'documents'])->name('maintenance.documents.index');
            Route::get('maintenance/{workOrder}/conversations', [MaintenanceController::class, 'conversations'])->name('maintenance.conversations.index');
            Route::get('maintenance/{workOrder}/texts', [MaintenanceController::class, 'texts'])->name('maintenance.texts');
            Route::get('maintenance/{workOrder}/documents/{document}', [MaintenanceController::class, 'downloadDocument'])->name('maintenance.documents.show');
            Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
            Route::get('documents/upload-targets', [DocumentController::class, 'uploadTargets'])->name('documents.upload-targets');
            Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:10,1')->name('documents.store');
            Route::get('documents/{document}', [DocumentController::class, 'download'])->name('documents.show');
        });
    });
});

// Private channel authorization for the mobile apps, with their Sanctum token.
Broadcast::routes(['prefix' => 'v1', 'middleware' => ['auth:sanctum']]);
