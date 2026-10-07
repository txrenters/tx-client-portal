<?php

namespace App\Listeners;

use App\Services\Admin\LandingPageTracker;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;

/**
 * Counts a web sign-up or sign-in for Insights when the same visitor saw the
 * landing page earlier that day.
 */
class RecordLandingConversions
{
    public function __construct(private LandingPageTracker $tracker) {}

    public function handleRegistered(Registered $event): void
    {
        if (! request()->is('api/*')) {
            $this->tracker->record(request(), LandingPageTracker::SIGN_UP);
        }
    }

    public function handleLogin(Login $event): void
    {
        // Signing up signs the new client in too; that is a sign-up, not a sign-in.
        if (! request()->is('api/*') && ! request()->routeIs('register*')) {
            $this->tracker->record(request(), LandingPageTracker::SIGN_IN);
        }
    }
}
