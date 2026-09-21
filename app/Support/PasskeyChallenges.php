<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Support\WebAuthn;

/**
 * Holds WebAuthn options between the two steps of an app's passkey ceremony.
 *
 * The web portal keeps them in the session; API clients have none, so the
 * options are cached under a random id the app sends back, usable once.
 */
class PasskeyChallenges
{
    private const SECONDS = 300;

    /**
     * Remember the options and return the id the app must send back.
     */
    public function put(string $purpose, object $options, int|string|null $userId = null): string
    {
        $challengeId = (string) Str::uuid();

        Cache::put($this->key($purpose, $challengeId, $userId), WebAuthn::toJson($options), self::SECONDS);

        return $challengeId;
    }

    /**
     * Take the options back out, so a challenge can't be replayed.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     *
     * @throws ValidationException
     */
    public function pull(string $purpose, string $challengeId, string $class, int|string|null $userId = null): object
    {
        $serialized = Cache::pull($this->key($purpose, $challengeId, $userId));

        if (! is_string($serialized)) {
            throw ValidationException::withMessages([
                'challenge_id' => __('Passkey verification session expired. Please try again.'),
            ]);
        }

        return WebAuthn::fromJson($serialized, $class);
    }

    private function key(string $purpose, string $challengeId, int|string|null $userId): string
    {
        return "passkeys:api:{$purpose}:".($userId ?? 'guest').":{$challengeId}";
    }
}
