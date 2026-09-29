<?php

namespace App\Services\Admin;

use App\Models\SignInEvent;
use App\Models\User;
use App\Services\Security\IpGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes the sign-in log. The web sign-ins arrive through Laravel's auth
 * events (see RecordSignInEvents); the app's token sign-ins never touch the
 * session guard, so the API controllers call this directly.
 */
class SignInLog
{
    /**
     * Set on the request around a login that is not the person signing in —
     * an admin starting or ending "sign in as" — so it is not logged as one.
     */
    public const SKIP = 'sign_in_log.skip';

    public function __construct(private IpGuard $guard) {}

    public function record(Request $request, string $event, ?User $user, ?string $email, string $channel, ?string $method = null): void
    {
        if ($request->attributes->get(self::SKIP) === true) {
            return;
        }

        $event = SignInEvent::create([
            'user_id' => $user?->id,
            'email' => $email === null ? $user?->email : Str::lower(Str::limit(trim($email), 250, '')),
            'event' => $event,
            'channel' => $channel,
            'method' => $method,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() === null ? null : Str::limit($request->userAgent(), 500, ''),
        ]);

        if ($event->event !== 'signed_in' && $event->event !== 'blocked') {
            $this->guard->assessSignInFailure($event->ip_address);
        }
    }
}
