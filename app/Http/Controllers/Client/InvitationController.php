<?php

namespace App\Http\Controllers\Client;

use App\Actions\Client\AcceptClientInvitation;
use App\Actions\Client\RegisterInvitedClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\AcceptInvitationRequest;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    /**
     * Show the invitation acceptance page.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = ClientInvitation::findPending($token);

        if ($invitation === null) {
            return Inertia::render('auth/AcceptInvitation', ['invitation' => null]);
        }

        $hasExistingAccount = User::where('email', $invitation->email)->exists();

        if ($hasExistingAccount && $request->user() === null) {
            return redirect()->guest(route('login'))
                ->with('status', __('Sign in to accept your invitation.'));
        }

        return Inertia::render('auth/AcceptInvitation', [
            'invitation' => [
                'token' => $token,
                'name' => $invitation->name,
                'email' => $invitation->email,
                'portal' => $invitation->role->portalLabel(),
            ],
            'requiresPassword' => ! $hasExistingAccount,
            'signedInAsOtherUser' => $request->user() !== null && $request->user()->email !== $invitation->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Accept the invitation, creating the client's login when they don't have one yet.
     */
    public function store(AcceptInvitationRequest $request, string $token, AcceptClientInvitation $acceptInvitation, RegisterInvitedClient $registerInvitedClient): RedirectResponse
    {
        $invitation = ClientInvitation::findPending($token);

        abort_if($invitation === null, 404);

        $user = $request->user();

        if ($user !== null) {
            abort_unless($user->email === $invitation->email, 403);

            $acceptInvitation->handle($invitation, $user);

            return to_route($invitation->role->dashboardRoute());
        }

        abort_if(User::where('email', $invitation->email)->exists(), 403);

        $user = $registerInvitedClient->handle($invitation, $request->validated('name'), $request->validated('password'));

        Auth::login($user);

        $request->session()->regenerate();

        return to_route($invitation->role->dashboardRoute());
    }
}
