<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\ImpersonationController;
use App\Services\Security\ClientWebPause;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign a client out of the website while staff have client sign-in paused.
 *
 * Sign-in already turns them away; this catches the browser sessions that
 * were open when the pause began. Web only, so app tokens keep working.
 */
class EnsureClientWebIsNotPaused
{
    public function __construct(private ClientWebPause $pause) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Staff looking at a client's portal on their behalf may stay.
        if ($user === null || $request->session()->has(ImpersonationController::SESSION_KEY) || ! $this->pause->shutsOut($user)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => __(ClientWebPause::MESSAGE)], 401);
        }

        return redirect()->route('login')->with('status', __(ClientWebPause::MESSAGE));
    }
}
