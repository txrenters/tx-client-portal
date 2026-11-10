<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Admin\SignInLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Logs web sign-ins and failed attempts from Laravel's and Fortify's events.
 */
class RecordSignInEvents
{
    public function __construct(private SignInLog $log) {}

    public function handleLogin(Login $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->log->record(request(), 'signed_in', $user, null, 'web', $event->remember ? 'remembered' : $this->method());
    }

    public function handleFailed(Failed $event): void
    {
        $user = $event->user instanceof User ? $event->user : null;

        $this->log->record(request(), 'failed', $user, $event->credentials['email'] ?? null, 'web', 'password');
    }

    public function handleLockout(Lockout $event): void
    {
        $this->log->record($event->request, 'locked_out', null, $event->request->input('email'), 'web', 'password');
    }

    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->log->record(request(), 'failed_two_factor', $event->user, null, 'web', 'two_factor');
    }

    /**
     * Which way a web sign-in came in, from the route that did it.
     */
    private function method(): string
    {
        return match (true) {
            request()->routeIs('portal.*') => 'portal',
            request()->routeIs('*passkey*') => 'passkey',
            request()->routeIs('two-factor.*') => 'two_factor',
            default => 'password',
        };
    }
}
