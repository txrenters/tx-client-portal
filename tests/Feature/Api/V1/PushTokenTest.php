<?php

use App\Models\PushToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function expoToken(string $suffix = 'aaaaaaaaaaaaaaaaaaaaaa'): string
{
    return "ExponentPushToken[{$suffix}]";
}

test('the app registers a phone so the portal can ring it', function () {
    $this->postJson(route('api.v1.push-tokens.store'), [
        'token' => expoToken(),
        'platform' => 'ios',
        'device_name' => "Rita's iPhone",
    ])->assertCreated()->assertJsonPath('data.platform', 'ios')->assertJsonPath('data.deviceName', "Rita's iPhone");

    $device = PushToken::sole();
    expect($device->token)->toBe(expoToken())
        ->and($device->user->is($this->user))->toBeTrue();
});

test('registering the same phone again updates it rather than duplicating it', function () {
    $this->postJson(route('api.v1.push-tokens.store'), ['token' => expoToken(), 'platform' => 'ios', 'device_name' => 'Old name'])
        ->assertCreated();

    $this->postJson(route('api.v1.push-tokens.store'), ['token' => expoToken(), 'platform' => 'ios', 'device_name' => 'New name'])
        ->assertOk();

    expect(PushToken::count())->toBe(1)
        ->and(PushToken::sole()->device_name)->toBe('New name');
});

test('a phone handed to another client follows the login now using it', function () {
    $previous = User::factory()->create();
    PushToken::factory()->for($previous)->create(['token' => expoToken()]);

    $this->postJson(route('api.v1.push-tokens.store'), ['token' => expoToken(), 'platform' => 'ios'])
        ->assertOk();

    expect(PushToken::count())->toBe(1)
        ->and(PushToken::sole()->user->is($this->user))->toBeTrue();
});

test('refuses anything that is not an Expo push token', function () {
    $this->postJson(route('api.v1.push-tokens.store'), ['token' => 'not-a-token', 'platform' => 'ios'])
        ->assertJsonValidationErrors(['token' => 'That is not an Expo push token.']);

    $this->postJson(route('api.v1.push-tokens.store'), ['token' => expoToken(), 'platform' => 'windows'])
        ->assertJsonValidationErrors('platform');

    expect(PushToken::count())->toBe(0);
});

test('signing out retires the phone', function () {
    PushToken::factory()->for($this->user)->create(['token' => expoToken()]);

    $this->deleteJson(route('api.v1.push-tokens.destroy'), ['token' => expoToken()])
        ->assertNoContent();

    expect(PushToken::count())->toBe(0);
});

test('one client cannot retire another client\'s phone', function () {
    $other = User::factory()->create();
    PushToken::factory()->for($other)->create(['token' => expoToken()]);

    $this->deleteJson(route('api.v1.push-tokens.destroy'), ['token' => expoToken()])
        ->assertNoContent();

    expect(PushToken::count())->toBe(1);
});

test('a signed-out request cannot register a phone', function () {
    app()['auth']->forgetGuards();

    $this->postJson(route('api.v1.push-tokens.store'), ['token' => expoToken(), 'platform' => 'ios'])
        ->assertUnauthorized();
});

test('the client can turn pushed replies off, and it is reported with the rest', function () {
    $this->getJson(route('api.v1.notification-preferences.show'))
        ->assertOk()
        ->assertJsonPath('data.message_replies_push', true);

    $this->putJson(route('api.v1.notification-preferences.update'), ['message_replies_push' => false])
        ->assertOk()
        ->assertJsonPath('data.message_replies_push', false)
        ->assertJsonPath('data.message_replies_email', true);

    expect($this->user->fresh()->wantsNotification('message_replies_push'))->toBeFalse();
});
