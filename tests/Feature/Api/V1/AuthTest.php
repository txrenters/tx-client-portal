<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\Sanctum;

test('registers a login, returns a token and emails a verification link', function () {
    Notification::fake();

    $response = $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Taylor Tenant',
        'email' => 'taylor@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'iPhone',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'taylor@example.com')
        ->assertJsonPath('user.emailVerified', false);
    expect($response->json('token'))->toBeString()->not->toBeEmpty();
    $user = User::where('email', 'taylor@example.com')->sole();
    expect($user->tokens()->sole()->name)->toBe('iPhone');
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('rejects a registration for an email that already has a login', function () {
    User::factory()->create(['email' => 'taylor@example.com']);

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Taylor Tenant',
        'email' => 'taylor@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'iPhone',
    ])->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
});

test('issues a token for valid credentials', function () {
    $user = User::factory()->create();

    $response = $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel',
    ]);

    $response->assertOk()->assertJsonPath('user.id', $user->id);
    expect($user->tokens()->sole()->name)->toBe('Pixel');
});

test('refuses a wrong password without issuing a token', function () {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'Pixel',
    ])->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

    expect($user->tokens()->count())->toBe(0);
});

test('asks for an authenticator code when two factor is enabled', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel',
    ])->assertJsonValidationErrors(['code' => 'Enter the code from your authenticator app.']);

    expect($user->tokens()->count())->toBe(0);
});

test('issues a token for a valid authenticator code', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->mock(TwoFactorAuthenticationProvider::class)
        ->shouldReceive('verify')->with('secret', '123456')->andReturn(true);

    $this->postJson(route('api.v1.auth.login'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Pixel',
        'code' => '123456',
    ])->assertOk();

    expect($user->tokens()->count())->toBe(1);
});

test('accepts a recovery code only once', function () {
    $user = User::factory()->withTwoFactor()->create();
    $credentials = ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel', 'recovery_code' => 'recovery-code-1'];

    $this->postJson(route('api.v1.auth.login'), $credentials)->assertOk();

    $this->postJson(route('api.v1.auth.login'), $credentials)
        ->assertJsonValidationErrors(['recovery_code' => 'The provided two factor recovery code was invalid.']);
});

test('logging out revokes only the token that made the request', function () {
    $user = User::factory()->create();
    $phone = $user->createToken('iPhone')->plainTextToken;
    $user->createToken('iPad');

    $this->withToken($phone)->postJson(route('api.v1.auth.logout'))->assertNoContent();

    expect($user->tokens()->pluck('name')->all())->toBe(['iPad']);
});

test('answers 401 without a token', function () {
    $this->getJson(route('api.v1.me'))->assertUnauthorized();
});

test('answers json even when the app omits the accept header', function () {
    $this->get(route('api.v1.me'))->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
});

test('shows the signed-in user with their linked accounts', function () {
    config(['services.propertyware.discover_accounts' => false]);
    $user = User::factory()->resident()->create();
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.accounts.0.role', 'resident');
});

test('sends another verification link to an unverified login', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.verification.send'))->assertAccepted();

    Notification::assertSentTo($user, VerifyEmail::class);
});
