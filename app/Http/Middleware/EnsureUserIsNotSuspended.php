<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\ImpersonationController;
use App\Models\User;
use App\Services\Admin\SignInLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turn away a login staff have suspended, wherever it is still signed in.
 *
 * Runs on every web and API request rather than only at sign-in, so a browser
 * tab left open or an app token issued before the suspension stops working on
 * its next request too.
 */
class EnsureUserIsNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isSuspended()) {
            return $next($request);
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            $token = $user->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['message' => __('This account has been suspended. Contact TexasRenters.com for help.')], 403);
        }

        // An admin looking at a client who was suspended mid-visit gets their
        // own account back rather than being signed out of it.
        $admin = User::query()->find($request->session()->pull(ImpersonationController::SESSION_KEY));

        if ($admin instanceof User) {
            $request->attributes->set(SignInLog::SKIP, true);
            Auth::guard('web')->login($admin);

            return redirect()->route('admin.users.show', $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __('This account has been suspended. Contact TexasRenters.com for help.'));
    }
}
