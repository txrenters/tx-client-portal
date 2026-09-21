<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\Response;

class ProfileController extends Controller
{
    /**
     * Update the user's name or email; a new email must be confirmed again.
     */
    public function update(ProfileUpdateRequest $request): UserResource
    {
        $user = $request->user()->fill($request->validated());
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return new UserResource($user->load('clientAccounts'));
    }

    /**
     * Delete the user's login and every token issued to it.
     */
    public function destroy(ProfileDeleteRequest $request): Response
    {
        $user = $request->user();

        $user->tokens()->delete();
        $user->delete();

        return response()->noContent();
    }
}
