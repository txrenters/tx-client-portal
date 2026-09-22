<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    /** Where the original admin is remembered for the length of the visit. */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Sign in as another user to see what they see.
     *
     * The admin's own id is kept in the session so the visit can be handed
     * back, and every start and stop is logged: acting as someone else is the
     * sort of thing that has to be answerable afterwards.
     *
     * Two things this refuses. An admin cannot impersonate another admin, so
     * the feature cannot be used to borrow someone else's staff access. And it
     * cannot be started while already impersonating, so a session can never
     * hold a chain it might unwind to the wrong person.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        if ($request->session()->has(self::SESSION_KEY)) {
            return back()->with('error', 'You are already signed in as someone else.');
        }

        if ($user->is($request->user())) {
            return back()->with('error', 'That is your own account.');
        }

        if (Gate::forUser($user)->allows('admin')) {
            return back()->with('error', 'Staff accounts cannot be impersonated.');
        }

        $admin = $request->user();

        Log::info('An admin started impersonating a user.', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
        ]);

        // The session id changes with the login, so the marker is written
        // after it rather than before, or it would be thrown away.
        Auth::login($user);
        $request->session()->put(self::SESSION_KEY, $admin->id);

        return to_route('dashboard')
            ->with('success', "You are now signed in as {$user->name}.");
    }

    /**
     * Hand the visit back to the admin who started it.
     *
     * Deliberately not behind the admin gate: the session is the client at
     * this point, so the only thing that may end it is the marker itself.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);

        if (! is_int($adminId)) {
            return to_route('dashboard');
        }

        $admin = User::query()->whereKey($adminId)->first();

        if ($admin === null) {
            Auth::logout();
            $request->session()->invalidate();

            return to_route('login');
        }

        Log::info('An admin stopped impersonating a user.', [
            'admin_id' => $admin->id,
            'user_id' => $request->user()?->id,
        ]);

        Auth::login($admin);

        return to_route('admin.users.index')->with('success', 'You are back on your own account.');
    }
}
