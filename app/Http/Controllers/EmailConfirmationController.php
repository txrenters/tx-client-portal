<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailConfirmationController extends Controller
{
    /**
     * Confirm an email address from the signed link, without requiring a login.
     *
     * Clients who only use the mobile app open this link in their phone's
     * browser, where they are not signed in. The signature proves the link came
     * from our email, and the hash ties it to the address it was sent to.
     * An app that opened the link itself asks for JSON instead of the page.
     */
    public function __invoke(Request $request, string $id, string $hash): Response|RedirectResponse|JsonResponse
    {
        $user = User::find($id);

        abort_if($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Your email address is confirmed.')]);
        }

        if ($request->user()?->is($user)) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        return Inertia::render('auth/EmailConfirmed', ['email' => $user->email]);
    }
}
