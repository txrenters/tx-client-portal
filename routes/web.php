<?php

use App\Http\Controllers\Client\InvitationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailConfirmationController;
use App\Http\Controllers\MobileAppAssociationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::inertia('privacy', 'Privacy')->name('privacy');
Route::inertia('support', 'Support')->name('support');

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
