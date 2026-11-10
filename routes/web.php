<?php

use App\Http\Controllers\Client\InvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailConfirmationController;
use App\Http\Controllers\GetTheAppController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\LandingPageEventController;
use App\Http\Controllers\MobileAppAssociationController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingPageController::class)->name('home');
Route::post('landing/events', LandingPageEventController::class)->middleware('throttle:30,1')->name('landing.events');

Route::inertia('privacy', 'Privacy')->name('privacy');
Route::inertia('support', 'Support')->name('support');
Route::get('get-app', GetTheAppController::class)->name('get-app');

Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}', [InvitationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('invitations.store');

Route::get('.well-known/apple-app-site-association', [MobileAppAssociationController::class, 'apple'])->name('well-known.apple-app-site-association');
Route::get('.well-known/assetlinks.json', [MobileAppAssociationController::class, 'android'])->name('well-known.assetlinks');

Route::get('email/confirm/{id}/{hash}', EmailConfirmationController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.confirm');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/client.php';
require __DIR__.'/settings.php';
require __DIR__.'/portal.php';

// Unknown addresses go through the web middleware like any page, so the 404
// page knows who is signed in and shows their own header and dashboard link.
// Every method, not just GET, so a POST to an unknown address stays a 404
// rather than a 405.
Route::any('{fallbackPlaceholder}', fn () => abort(404))->where('fallbackPlaceholder', '.*')->fallback();
