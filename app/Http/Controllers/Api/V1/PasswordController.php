<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\Response;

class PasswordController extends Controller
{
    /**
     * Change the password and sign out the user's other devices.
     */
    public function update(PasswordUpdateRequest $request): Response
    {
        $user = $request->user();

        $user->update(['password' => $request->validated('password')]);

        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        return response()->noContent();
    }
}
