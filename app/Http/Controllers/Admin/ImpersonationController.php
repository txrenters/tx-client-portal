<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\SignInLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ImpersonationController extends Controller
{
    use FlashesToasts;

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
     * hold a chain it might unwind to the wrong person. A suspended login is
     * refused too, since the suspension would end the visit straight away.
     */
    public function store(Request $request, User $user, AdminAudit $audit): RedirectResponse
    {
        $refusal = match (true) {
            $request->session()->has(self::SESSION_KEY) => 'You are already signed in as someone else.',
            $user->is($request->user()) => 'That is your own account.',
            Gate::forUser($user)->allows('admin') => 'Staff accounts cannot be impersonated.',
            $user->isSuspended() => 'That account is suspended. Lift the suspension first.',
            default => null,
        };

        if ($refusal !== null) {
            $this->toast($refusal, 'error');

            return back();
        }

        $admin = $request->user();
        $audit->record($admin, 'impersonation_started', "Signed in as {$user->email}", $user);

        Log::info('An admin started impersonating a user.', [
            'admin_id' => $admin->id,
            'user_id' => $user->id,
        ]);

        // The session id changes with the login, so the marker is written
        // after it rather than before, or it would be thrown away. It is not
        // the client signing in, so it stays out of the sign-in log.
        $request->attributes->set(SignInLog::SKIP, true);
        Auth::login($user);
        $request->session()->put(self::SESSION_KEY, $admin->id);

        $this->toast("You are now signed in as {$user->name}.");

        return to_route('dashboard');
    }

    /**
     * Hand the visit back to the admin who started it.
     *
     * Deliberately not behind the admin gate: the session is the client at
     * this point, so the only thing that may end it is the marker itself.
     */
    public function destroy(Request $request, AdminAudit $audit): RedirectResponse
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

        $client = $request->user();

        $request->attributes->set(SignInLog::SKIP, true);
        Auth::login($admin);

        if ($client instanceof User) {
            $audit->record($admin, 'impersonation_stopped', "Stopped signing in as {$client->email}", $client);
        }

        $this->toast('You are back on your own account.');

        return $client instanceof User
            ? to_route('admin.users.show', $client)
            : to_route('admin.users.index');
    }
}
