<?php

use App\Http\Controllers\Client\AccountController;
use App\Http\Controllers\Client\NotificationController;
use App\Http\Controllers\Owner\ConversationController as OwnerConversationController;
use App\Http\Controllers\Owner\DashboardController as OwnerDashboardController;
use App\Http\Controllers\Owner\DocumentController as OwnerDocumentController;
use App\Http\Controllers\Owner\FinancialController as OwnerFinancialController;
use App\Http\Controllers\Owner\MaintenanceController as OwnerMaintenanceController;
use App\Http\Controllers\Owner\MessageController as OwnerMessageController;
use App\Http\Controllers\Owner\PortfolioController as OwnerPortfolioController;
use App\Http\Controllers\Owner\PropertyController as OwnerPropertyController;
use App\Http\Controllers\Resident\ConversationController as ResidentConversationController;
use App\Http\Controllers\Resident\DashboardController as ResidentDashboardController;
use App\Http\Controllers\Resident\DocumentController as ResidentDocumentController;
use App\Http\Controllers\Resident\LeaseController as ResidentLeaseController;
use App\Http\Controllers\Resident\MaintenanceController as ResidentMaintenanceController;
use App\Http\Controllers\Resident\MessageController as ResidentMessageController;
use App\Http\Controllers\Resident\PropertyController as ResidentPropertyController;
use App\Http\Middleware\EnsureClientRole;
use Illuminate\Support\Facades\Route;

/*
 * Section pages not built yet render the shared "coming soon" shell so navigation stays complete.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware(EnsureClientRole::class.':resident')->prefix('resident')->name('resident.')->group(function () {
        Route::get('dashboard', ResidentDashboardController::class)->name('dashboard');
        Route::get('property', [ResidentPropertyController::class, 'show'])->name('property');
        Route::get('maintenance', [ResidentMaintenanceController::class, 'index'])->name('maintenance.index');
        Route::post('maintenance', [ResidentMaintenanceController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('maintenance.store');
        Route::get('maintenance/{workOrder}', [ResidentMaintenanceController::class, 'show'])->name('maintenance.show');
        Route::get('maintenance/{workOrder}/documents/{document}/download', [ResidentMaintenanceController::class, 'downloadDocument'])
            ->name('maintenance.documents.download');
        Route::get('maintenance/{workOrder}/documents/{document}/preview', [ResidentMaintenanceController::class, 'previewDocument'])
            ->name('maintenance.documents.preview');
        Route::get('messages', [ResidentMessageController::class, 'index'])->name('messages.index');
        Route::post('messages', [ResidentMessageController::class, 'store'])->middleware('throttle:10,1')->name('messages.store');
        Route::post('messages/{thread}/replies', [ResidentMessageController::class, 'reply'])->whereNumber('thread')->middleware('throttle:30,1')->name('messages.reply');
        Route::get('conversations', [ResidentConversationController::class, 'index'])->name('conversations.index');
        Route::get('lease', ResidentLeaseController::class)->name('lease');
        // Payments live on the lease page now; the old address still works.
        Route::redirect('payments', '/resident/lease')->name('payments');
        Route::get('documents', [ResidentDocumentController::class, 'index'])->name('documents');
        Route::post('documents', [ResidentDocumentController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('documents.store');
        Route::get('documents/{document}/download', [ResidentDocumentController::class, 'download'])
            ->name('documents.download');
        Route::get('documents/{document}/preview', [ResidentDocumentController::class, 'preview'])
            ->name('documents.preview');
    });

    Route::middleware(EnsureClientRole::class.':owner')->prefix('owner')->name('owner.')->group(function () {
        Route::get('dashboard', OwnerDashboardController::class)->name('dashboard');
        Route::get('portfolio', OwnerPortfolioController::class)->name('portfolio');
        Route::get('properties', [OwnerPropertyController::class, 'index'])->name('properties.index');
        Route::get('properties/{property}', [OwnerPropertyController::class, 'show'])->name('properties.show');
        Route::get('maintenance', [OwnerMaintenanceController::class, 'index'])->name('maintenance.index');
        Route::post('maintenance', [OwnerMaintenanceController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('maintenance.store');
        Route::get('maintenance/{workOrder}', [OwnerMaintenanceController::class, 'show'])->name('maintenance.show');
        Route::post('maintenance/{workOrder}/approval', [OwnerMaintenanceController::class, 'decide'])
            ->middleware('throttle:10,1')
            ->name('maintenance.approval');
        Route::get('maintenance/{workOrder}/documents/{document}/download', [OwnerMaintenanceController::class, 'downloadDocument'])
            ->name('maintenance.documents.download');
        Route::get('maintenance/{workOrder}/documents/{document}/preview', [OwnerMaintenanceController::class, 'previewDocument'])
            ->name('maintenance.documents.preview');
        Route::get('messages', [OwnerMessageController::class, 'index'])->name('messages.index');
        Route::post('messages', [OwnerMessageController::class, 'store'])->middleware('throttle:10,1')->name('messages.store');
        Route::post('messages/{thread}/replies', [OwnerMessageController::class, 'reply'])->whereNumber('thread')->middleware('throttle:30,1')->name('messages.reply');
        Route::get('conversations', [OwnerConversationController::class, 'index'])->name('conversations.index');
        Route::get('financials', OwnerFinancialController::class)->name('financials');
        Route::inertia('statements', 'client/ComingSoon', ['title' => 'Statements'])->name('statements');
        Route::get('documents', [OwnerDocumentController::class, 'index'])->name('documents');
        Route::post('documents', [OwnerDocumentController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('documents.store');
        Route::get('documents/{document}/download', [OwnerDocumentController::class, 'download'])
            ->name('documents.download');
        Route::get('documents/{document}/preview', [OwnerDocumentController::class, 'preview'])
            ->name('documents.preview');
    });

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::put('accounts/{clientAccount}', [AccountController::class, 'update'])->name('accounts.update');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
    Route::inertia('help', 'client/ComingSoon', ['title' => 'Help'])->name('help');
});
