<?php

namespace App\Http\Requests\Api\V1;

use App\Support\PasskeyChallenges;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeyLoginRequest extends PasskeyVerificationRequest
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
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Get the options issued for this sign-in from the cache rather than the session.
     */
    public function verificationOptions(): PublicKeyCredentialRequestOptions
    {
        return app(PasskeyChallenges::class)->pull('login', $this->string('challenge_id')->toString(), PublicKeyCredentialRequestOptions::class);
    }
}
