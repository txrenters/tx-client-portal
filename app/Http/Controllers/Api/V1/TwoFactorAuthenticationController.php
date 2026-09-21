<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CurrentPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class TwoFactorAuthenticationController extends Controller
{
    /**
     * Start two-factor setup and return what the authenticator app needs.
     *
     * Two-factor stays off until the user confirms a code from the new secret.
     */
    public function store(CurrentPasswordRequest $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $user = $request->user();

        $enable($user, force: true);

        return response()->json(['data' => [
            'otpauthUrl' => $user->twoFactorQrCodeUrl(),
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'setupKey' => decrypt($user->two_factor_secret),
        ]], 201);
    }

    /**
     * Turn two-factor on with a code from the authenticator app.
     */
    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $confirm($request->user(), $request->input('code'));

        return response()->json(['data' => ['recoveryCodes' => $request->user()->recoveryCodes()]]);
    }

    /**
     * Replace the recovery codes, invalidating the old ones.
     */
    public function regenerateRecoveryCodes(CurrentPasswordRequest $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        abort_unless($request->user()->hasEnabledTwoFactorAuthentication(), 409, __('Two-factor authentication is not enabled.'));

        $generate($request->user());

        return response()->json(['data' => ['recoveryCodes' => $request->user()->fresh()->recoveryCodes()]]);
    }

    /**
     * Turn two-factor off.
     */
    public function destroy(CurrentPasswordRequest $request, DisableTwoFactorAuthentication $disable): Response
    {
        $disable($request->user());

        return response()->noContent();
    }
}
