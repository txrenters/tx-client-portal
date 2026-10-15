<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use App\Services\Propertyware\TerminatedLeaseGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign out a resident whose lease has been terminated, wherever they are still signed in.
 *
 * Sign-in already turns them away; this catches the browser session or app
 * token issued while the lease was still running. The answer is kept for a few
 * minutes per login so a page view does not wait on Propertyware each time.
 */
class EnsureLeaseIsNotTerminated
{
    public const CACHE_SECONDS = 600;

    public function __construct(private TerminatedLeaseGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Staff looking at a former resident's portal on their behalf may stay.
        if ($user === null || ($request->hasSession() && $request->session()->has(ImpersonationController::SESSION_KEY))) {
            return $next($request);
        }

        // A login with no account linked yet is about to be matched to one, so
        // its answer is not kept: a former resident who signs up again is caught
        // on the request after their account is found, not ten minutes later.
        $shutOut = $user->clientAccounts()->exists()
            ? Cache::remember(self::cacheKey($user), self::CACHE_SECONDS, fn (): bool => $this->gate->shutsOut($user))
            : false;

        if (! $shutOut) {
            return $next($request);
        }

        // A 401, not a 403: the apps sign out on a 401 from any screen, and
        // show this message when the resident next tries to sign in.
        if ($request->is('api/*') || $request->expectsJson()) {
            $this->revokeBearerToken($request, $user);

            return response()->json(['message' => __(TerminatedLeaseGate::MESSAGE)], 401);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __(TerminatedLeaseGate::MESSAGE));
    }

    public static function cacheKey(User $user): string
    {
        return "terminated-lease:{$user->id}";
    }

    /**
     * Revoke the app token the request came in with, so it stops working for good.
     */
    private function revokeBearerToken(Request $request, User $user): void
    {
        $bearer = $request->bearerToken();
        $token = $bearer === null ? null : PersonalAccessToken::findToken($bearer);

        if ($token !== null) {
            $user->tokens()->whereKey($token->getKey())->delete();
        }
    }
}
