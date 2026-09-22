<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\UserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Everyone who has an account on the portal.
     */
    public function index(Request $request, UserDirectory $directory): Response
    {
        $search = $request->string('search')->trim()->value();

        return Inertia::render('admin/Users', [
            'search' => $search,
            'users' => $directory->paginate($search === '' ? null : $search),
        ]);
    }

    /**
     * Email the user a password reset link.
     */
    public function sendPasswordReset(User $user, UserDirectory $directory): RedirectResponse
    {
        $result = $directory->sendPasswordReset($user);

        return $result['sent']
            ? back()->with('success', "A reset link is on its way to {$user->email}.")
            : back()->with('error', $result['reason']);
    }

    /**
     * Sign the user out of the mobile apps.
     */
    public function revokeAppSessions(User $user, UserDirectory $directory): RedirectResponse
    {
        $revoked = $directory->revokeAppSessions($user);

        return back()->with('success', $revoked === 0
            ? 'That account had no app sessions to sign out.'
            : "Signed {$user->email} out of {$revoked} app session(s).");
    }

    /**
     * Forget the user's Propertyware links so they are worked out again.
     */
    public function relinkAccounts(User $user, UserDirectory $directory): RedirectResponse
    {
        $removed = $directory->relinkAccounts($user);

        return back()->with('success', "Cleared {$removed} link(s). They will be matched to Propertyware again on their next visit.");
    }
}
