<?php

namespace App\Http\Requests\Api\V1;

use App\Support\PasskeyChallenges;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Webauthn\PublicKeyCredentialCreationOptions;

class StorePasskeyRequest extends PasskeyRegistrationRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'challenge_id' => ['required', 'string'],
        ];
    }

    /**
     * Get the options issued to this user from the cache rather than the session.
     */
    public function registrationOptions(): PublicKeyCredentialCreationOptions
    {
        return app(PasskeyChallenges::class)->pull('register', $this->string('challenge_id')->toString(), PublicKeyCredentialCreationOptions::class, $this->user()->getKey());
    }
}
