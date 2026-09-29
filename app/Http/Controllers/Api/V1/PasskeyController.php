<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CurrentPasswordRequest;
use App\Http\Requests\Api\V1\PasskeyLoginRequest;
use App\Http\Requests\Api\V1\StorePasskeyRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use App\Services\Admin\SignInLog;
use App\Support\PasskeyChallenges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;

class PasskeyController extends Controller
{
    /**
     * Start a passkey sign-in: the app hands these options to the platform authenticator.
     */
    public function loginOptions(GenerateVerificationOptions $generate, PasskeyChallenges $challenges): JsonResponse
    {
        $options = $generate();

        return response()->json([
            'challengeId' => $challenges->put('login', $options),
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Finish a passkey sign-in and issue a token for this device.
     *
     * @throws ValidationException
     */
    public function login(PasskeyLoginRequest $request, VerifyPasskey $verify, SignInLog $log): JsonResponse
    {
        $passkey = $verify($request->credential(), $request->verificationOptions());

        if (! Passkeys::allowsLogin($request, $passkey)) {
            throw ValidationException::withMessages(['credential' => __('Unable to sign in with this account.')]);
        }

        /** @var User $user */
        $user = $passkey->user;

        if ($user->isSuspended()) {
            $log->record($request, 'blocked', $user, null, 'app', 'passkey');

            throw ValidationException::withMessages(['credential' => __('This account has been suspended. Contact TexasRenters.com for help.')]);
        }

        $log->record($request, 'signed_in', $user, null, 'app', 'passkey');

        return response()->json([
            'token' => $user->createToken($request->validated('device_name'))->plainTextToken,
            'user' => new UserResource($user->load('clientAccounts')),
        ]);
    }

    /**
     * List the signed-in user's passkeys.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->passkeys()->latest()->get()
                ->map(fn (Passkey $passkey): array => $this->passkeyData($passkey))
                ->values(),
        ]);
    }

    /**
     * Start adding a passkey, after re-checking the password.
     */
    public function registrationOptions(CurrentPasswordRequest $request, GenerateRegistrationOptions $generate, PasskeyChallenges $challenges): JsonResponse
    {
        $options = $generate($request->user());

        return response()->json([
            'challengeId' => $challenges->put('register', $options, $request->user()->getKey()),
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Save the passkey the platform authenticator just created.
     */
    public function store(StorePasskeyRequest $request, StorePasskey $storePasskey): JsonResponse
    {
        $passkey = $storePasskey(
            $request->user(),
            $request->string('name')->toString(),
            $request->credential(),
            $request->registrationOptions(),
        );

        return response()->json(['data' => $this->passkeyData($passkey)], 201);
    }

    /**
     * Remove one of the user's passkeys; anyone else's answers as missing.
     */
    public function destroy(CurrentPasswordRequest $request, Passkey $passkey, DeletePasskey $deletePasskey): Response
    {
        abort_unless($passkey->user_id === $request->user()->getKey(), 404);

        $deletePasskey($request->user(), $passkey);

        return response()->noContent();
    }

    /**
     * @return array{id: int, name: string, authenticator: ?string, createdAt: ?string, lastUsedAt: ?string}
     */
    private function passkeyData(Passkey $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'authenticator' => $passkey->authenticator,
            'createdAt' => $passkey->created_at?->toIso8601String(),
            'lastUsedAt' => $passkey->last_used_at?->toIso8601String(),
        ];
    }
}
