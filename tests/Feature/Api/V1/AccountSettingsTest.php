<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

test('changing the email unverifies it and sends a confirmation link', function () {
    Notification::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.emailVerified', false);

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('keeps the email verified when only the name changes', function () {
    Notification::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->patchJson(route('api.v1.profile.update'), ['name' => 'New Name', 'email' => $user->email])
        ->assertJsonPath('data.emailVerified', true);

    Notification::assertNothingSent();
});

test('changing the password signs out the other devices', function () {
    $user = User::factory()->create();
    $phone = $user->createToken('iPhone')->plainTextToken;
    $user->createToken('iPad');

    $this->withToken($phone)->putJson(route('api.v1.password.update'), [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertNoContent();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->pluck('name')->all())->toBe(['iPhone']);
});

test('refuses a password change with the wrong current password', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->putJson(route('api.v1.password.update'), [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertJsonValidationErrors(['current_password' => 'The password is incorrect.']);
});

test('resets a forgotten password from the app and signs out every device', function () {
    $user = User::factory()->create();
    $user->createToken('iPhone');
    $token = Password::createToken($user);

    $this->postJson(route('api.v1.auth.reset-password'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertOk();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});

test('refuses a password reset with an invalid token', function () {
    $user = User::factory()->create();

    $this->postJson(route('api.v1.auth.reset-password'), [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertJsonValidationErrors(['email' => 'This password reset token is invalid.']);
});

test('deletes the account after checking the password', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.profile.destroy'), ['password' => 'wrong-password'])
        ->assertJsonValidationErrors('password');
    $this->deleteJson(route('api.v1.profile.destroy'), ['password' => 'password'])->assertNoContent();

    $this->assertModelMissing($user);
});

test('sets up two factor from the app and confirms it with a code', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $setup = $this->postJson(route('api.v1.two-factor.store'), ['password' => 'password'])->assertCreated();
    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $code = (new Google2FA)->getCurrentOtp($setup->json('data.setupKey'));
    $this->postJson(route('api.v1.two-factor.confirm'), ['code' => $code])
        ->assertOk()
        ->assertJsonCount(8, 'data.recoveryCodes');

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('turns two factor off after checking the password', function () {
    $user = User::factory()->withTwoFactor()->create();
    Sanctum::actingAs($user);

    $this->deleteJson(route('api.v1.two-factor.destroy'), ['password' => 'password'])->assertNoContent();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});
