<?php

use App\Models\User;
use Laravel\Passkeys\Passkey;
use Laravel\Sanctum\Sanctum;

function passkeyFor(User $user, string $name = 'iPhone'): Passkey
{
    return $user->passkeys()->create([
        'name' => $name,
        'credential_id' => fake()->uuid(),
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
}

function base64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/**
 * A well-formed credential whose challenge was never issued; its signature is never checked.
 */
function fakeCredential(string $type): array
{
    $authenticatorData = str_repeat("\0", 32)."\x05".pack('N', 1);
    $clientData = base64Url((string) json_encode(['type' => $type, 'challenge' => base64Url('challenge'), 'origin' => config('app.url')]));

    $response = $type === 'webauthn.get'
        ? ['clientDataJSON' => $clientData, 'authenticatorData' => base64Url($authenticatorData), 'signature' => base64Url('signature')]
        // CBOR map: {"fmt": "none", "attStmt": {}, "authData": <37 bytes>}
        : ['clientDataJSON' => $clientData, 'attestationObject' => base64Url("\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData\x58\x25".$authenticatorData)];

    return ['id' => base64Url('credential'), 'rawId' => base64Url('credential'), 'type' => 'public-key', 'response' => $response];
}

test('issues sign-in options with a one-time challenge id', function () {
    $response = $this->postJson(route('api.v1.auth.passkeys.options'))->assertOk();

    expect($response->json('challengeId'))->toBeUuid()
        ->and($response->json('options.rpId'))->toBe(parse_url(config('app.url'), PHP_URL_HOST))
        ->and($response->json('options.challenge'))->toBeString()->not->toBeEmpty();
});

test('refuses a sign-in whose challenge was never issued', function () {
    $this->postJson(route('api.v1.auth.passkeys.login'), [
        'challenge_id' => 'not-issued',
        'device_name' => 'iPhone',
        'credential' => fakeCredential('webauthn.get'),
    ])->assertJsonValidationErrors(['challenge_id' => 'Passkey verification session expired. Please try again.']);
});

test('asks for the password before adding a passkey', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.passkeys.options'), ['password' => 'wrong-password'])
        ->assertJsonValidationErrors('password');

    $this->postJson(route('api.v1.passkeys.options'), ['password' => 'password'])
        ->assertOk()
        ->assertJsonStructure(['challengeId', 'options' => ['challenge', 'rp', 'user']]);
});

test('refuses to save a passkey against another user\'s challenge', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    $challengeId = $this->postJson(route('api.v1.passkeys.options'), ['password' => 'password'])->json('challengeId');
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.passkeys.store'), [
        'challenge_id' => $challengeId,
        'name' => 'iPhone',
        'credential' => fakeCredential('webauthn.create'),
    ])->assertJsonValidationErrors('challenge_id');
});

test('lists only the signed-in user\'s passkeys', function () {
    $user = User::factory()->create();
    passkeyFor($user, 'iPhone');
    passkeyFor(User::factory()->create(), 'Someone else');
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.passkeys.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'iPhone');
});

test('removes a passkey after checking the password', function () {
    $user = User::factory()->create();
    $passkey = passkeyFor($user);
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.passkeys.destroy', $passkey), ['password' => 'password'])->assertNoContent();

    $this->assertModelMissing($passkey);
});

test('answers 404 when removing someone else\'s passkey', function () {
    $passkey = passkeyFor(User::factory()->create());
    Sanctum::actingAs(User::factory()->create());

    $this->deleteJson(route('api.v1.passkeys.destroy', $passkey), ['password' => 'password'])->assertNotFound();

    $this->assertModelExists($passkey);
});
