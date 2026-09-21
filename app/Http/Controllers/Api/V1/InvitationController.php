<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Client\AcceptClientInvitation;
use App\Actions\Client\RegisterInvitedClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptInvitationRequest;
use App\Http\Resources\V1\ClientAccountResource;
use App\Http\Resources\V1\UserResource;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    /**
     * Show who an invitation is for, so the app can prefill its sign-up screen.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->findInvitation($token);

        return response()->json(['data' => [
            'name' => $invitation->name,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'portal' => $invitation->role->portalLabel(),
            'hasAccount' => User::where('email', $invitation->email)->exists(),
        ]]);
    }

    /**
     * Create the invited client's login from the app and sign them in.
     */
    public function store(AcceptInvitationRequest $request, string $token, RegisterInvitedClient $registerInvitedClient): JsonResponse
    {
        $invitation = $this->findInvitation($token);

        abort_if(
            User::where('email', $invitation->email)->exists(),
            409,
            __('This email already has a login. Log in, then accept the invitation.'),
        );

        $user = $registerInvitedClient->handle($invitation, $request->validated('name'), $request->validated('password'));

        return response()->json([
            'token' => $user->createToken($request->validated('device_name'))->plainTextToken,
            'user' => new UserResource($user->load('clientAccounts')),
        ], 201);
    }

    /**
     * Link an invitation to the signed-in client, who must hold the invited email.
     */
    public function accept(Request $request, string $token, AcceptClientInvitation $acceptInvitation): ClientAccountResource
    {
        $invitation = $this->findInvitation($token);

        abort_unless($request->user()->email === $invitation->email, 403, __('This invitation was sent to a different email address.'));

        return new ClientAccountResource($acceptInvitation->handle($invitation, $request->user()));
    }

    /**
     * Find a pending invitation, or answer 404 for an unknown or expired one.
     */
    private function findInvitation(string $token): ClientInvitation
    {
        $invitation = ClientInvitation::findPending($token);

        abort_if($invitation === null, 404, __('This invitation is invalid or has expired.'));

        return $invitation;
    }
}
