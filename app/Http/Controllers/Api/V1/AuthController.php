<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Admin\SignInLog;
use App\Services\Propertyware\TerminatedLeaseGate;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthController extends Controller
{
    /**
     * Create a login and send the email verification link.
     */
    public function register(RegisterRequest $request, CreateNewUser $creator): JsonResponse
    {
        $user = $creator->create($request->only('name', 'email', 'password', 'password_confirmation'));

        event(new Registered($user));

        return $this->tokenResponse($user, $request->validated('device_name'))->setStatusCode(201);
    }

    /**
     * Exchange credentials, and a two-factor code when enabled, for an API token.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request, TwoFactorAuthenticationProvider $twoFactor, SignInLog $log, TerminatedLeaseGate $leases): JsonResponse
    {
        $email = $request->validated('email');
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            $log->record($request, 'failed', $user, $email, 'app', 'password');

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->isSuspended()) {
            $log->record($request, 'blocked', $user, $email, 'app', 'password');

            throw ValidationException::withMessages(['email' => __('This account has been suspended. Contact TexasRenters.com for help.')]);
        }

        if ($leases->shutsOut($user)) {
            $log->record($request, 'blocked', $user, $email, 'app', 'password');

            throw ValidationException::withMessages(['email' => __(TerminatedLeaseGate::MESSAGE)]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            try {
                $this->ensureTwoFactorChallengePassed($request, $user, $twoFactor);
            } catch (ValidationException $exception) {
                // Asking for the code is the first step of every 2FA sign-in,
                // not a failure; only a wrong code is.
                if (filled($request->validated('code')) || filled($request->validated('recovery_code'))) {
                    $log->record($request, 'failed_two_factor', $user, $email, 'app', 'two_factor');
                }

                throw $exception;
            }
        }

        $log->record($request, 'signed_in', $user, $email, 'app', $user->hasEnabledTwoFactorAuthentication() ? 'two_factor' : 'password');

        return $this->tokenResponse($user, $request->validated('device_name'));
    }

    /**
     * Revoke the token used for this request.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    /**
     * Email a password reset link, without revealing whether the address is registered.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => __('If that email is registered, a reset link is on its way.')]);
    }

    /**
     * Set a new password with the token from the reset email, signing out every device.
     *
     * @throws ValidationException
     */
    public function resetPassword(ResetPasswordRequest $request, ResetsUserPasswords $resetter): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request, $resetter): void {
                $resetter->reset($user, $request->only('password', 'password_confirmation'));
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }

    /**
     * Require a valid authenticator code or an unused recovery code.
     *
     * @throws ValidationException
     */
    private function ensureTwoFactorChallengePassed(LoginRequest $request, User $user, TwoFactorAuthenticationProvider $twoFactor): void
    {
        $code = $request->validated('code');
        $recoveryCode = $request->validated('recovery_code');

        if (filled($recoveryCode)) {
            $validCode = collect($user->recoveryCodes())->first(fn (string $candidate): bool => hash_equals($candidate, $recoveryCode));

            if ($validCode === null) {
                throw ValidationException::withMessages(['recovery_code' => __('The provided two factor recovery code was invalid.')]);
            }

            $user->replaceRecoveryCode($validCode);

            return;
        }

        if (blank($code)) {
            throw ValidationException::withMessages(['code' => __('Enter the code from your authenticator app.')]);
        }

        if (! $twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
            throw ValidationException::withMessages(['code' => __('The provided two factor authentication code was invalid.')]);
        }
    }

    /**
     * Issue a token for this device alongside the user it belongs to.
     */
    private function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => new UserResource($user->load('clientAccounts')),
        ]);
    }
}
