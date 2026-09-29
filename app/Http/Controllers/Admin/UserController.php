<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\FlashesToasts;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\AdminAudit;
use App\Services\Admin\UserDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support actions on one portal login. Every one is recoverable and every one
 * is written to the admin audit trail against the person it was done to.
 */
class UserController extends Controller
{
    use FlashesToasts;

    public function __construct(private UserDirectory $directory, private AdminAudit $audit) {}

    /**
     * Everyone who has an account on the portal.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:suspended,unverified'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        return Inertia::render('admin/Users', [
            'search' => $search,
            'filter' => $validated['filter'] ?? null,
            'users' => $this->directory->paginate($search === '' ? null : $search, $validated['filter'] ?? null),
        ]);
    }

    /**
     * One person, with their accounts, app sessions and what staff have done.
     */
    public function show(User $user): Response
    {
        return Inertia::render('admin/UserShow', [
            'user' => $this->directory->detail($user),
            'isStaff' => Gate::forUser($user)->allows('admin'),
            'history' => $this->audit->forUser($user),
        ]);
    }

    /**
     * Email the user a password reset link.
     */
    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        $result = $this->directory->sendPasswordReset($user);

        if (! $result['sent']) {
            $this->toast($result['reason'], 'error');

            return back();
        }

        $this->audit->record($request->user(), 'password_reset_sent', "Sent {$user->email} a password reset link", $user);
        $this->toast("A reset link is on its way to {$user->email}.");

        return back();
    }

    /**
     * Sign the user out of the mobile apps.
     */
    public function revokeAppSessions(Request $request, User $user): RedirectResponse
    {
        $revoked = $this->directory->revokeAppSessions($user);

        if ($revoked > 0) {
            $this->audit->record($request->user(), 'app_sessions_revoked', "Signed {$user->email} out of {$revoked} app session(s)", $user);
        }

        $this->toast($revoked === 0
            ? 'That account had no app sessions to sign out.'
            : "Signed {$user->email} out of {$revoked} app session(s).");

        return back();
    }

    /**
     * Forget the user's Propertyware links so they are worked out again.
     */
    public function relinkAccounts(Request $request, User $user): RedirectResponse
    {
        $removed = $this->directory->relinkAccounts($user);

        $this->audit->record($request->user(), 'accounts_relinked', "Cleared {$removed} Propertyware link(s) for {$user->email}", $user);
        $this->toast("Cleared {$removed} link(s). They will be matched to Propertyware again on their next visit.");

        return back();
    }

    /**
     * Send the email confirmation link again.
     */
    public function resendVerification(Request $request, User $user): RedirectResponse
    {
        if (! $this->directory->resendVerification($user)) {
            $this->toast('That email address is already confirmed.', 'error');

            return back();
        }

        $this->audit->record($request->user(), 'verification_resent', "Sent {$user->email} a new confirmation link", $user);
        $this->toast("A confirmation link is on its way to {$user->email}.");

        return back();
    }

    /**
     * Treat the email address as confirmed.
     */
    public function markVerified(Request $request, User $user): RedirectResponse
    {
        if (! $this->directory->markEmailVerified($user)) {
            $this->toast('That email address is already confirmed.', 'error');

            return back();
        }

        $this->audit->record($request->user(), 'email_marked_verified', "Marked {$user->email} as confirmed", $user);
        $this->toast("{$user->email} is now confirmed.");

        return back();
    }

    /**
     * Turn off two-factor for someone locked out of it.
     */
    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        if ($refusal = $this->refuseStaff($request, $user)) {
            return $refusal;
        }

        if (! $this->directory->resetTwoFactor($user)) {
            $this->toast('That account does not use two-factor.', 'error');

            return back();
        }

        $this->audit->record($request->user(), 'two_factor_reset', "Turned off two-factor for {$user->email}", $user);
        $this->toast("Two-factor is off for {$user->email}. They can set it up again from Settings.");

        return back();
    }

    /**
     * Shut the user out of the portal and the apps.
     */
    public function suspend(Request $request, User $user): RedirectResponse
    {
        if ($refusal = $this->refuseStaff($request, $user)) {
            return $refusal;
        }

        if ($user->isSuspended()) {
            $this->toast('That account is already suspended.', 'error');

            return back();
        }

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $this->directory->suspend($user);
        $this->audit->record(
            $request->user(),
            'suspended',
            "Suspended {$user->email}",
            $user,
            array_filter(['reason' => $validated['reason'] ?? null]),
        );
        $this->toast("{$user->email} is suspended and signed out everywhere.");

        return back();
    }

    /**
     * Let a suspended user sign in again.
     */
    public function restore(Request $request, User $user): RedirectResponse
    {
        if (! $user->isSuspended()) {
            $this->toast('That account is not suspended.', 'error');

            return back();
        }

        $this->directory->restore($user);
        $this->audit->record($request->user(), 'restored', "Lifted the suspension on {$user->email}", $user);
        $this->toast("{$user->email} can sign in again.");

        return back();
    }

    /**
     * Staff logins are not managed from here, and nobody manages their own:
     * locking yourself out, or another admin, is not a support action.
     */
    private function refuseStaff(Request $request, User $user): ?RedirectResponse
    {
        if ($user->is($request->user()) || Gate::forUser($user)->allows('admin')) {
            $this->toast('Staff accounts cannot be changed from here.', 'error');

            return back();
        }

        return null;
    }
}
