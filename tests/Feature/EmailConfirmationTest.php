<?php

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;

function confirmationUrl(User $user, ?string $email = null): string
{
    return URL::temporarySignedRoute('verification.confirm', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($email ?? $user->email),
    ]);
}

test('the verification email links to the login-free confirmation page', function () {
    $user = User::factory()->unverified()->create();

    $mail = (new VerifyEmail)->toMail($user);

    expect($mail->actionUrl)->toStartWith(route('verification.confirm', ['id' => $user->id, 'hash' => sha1($user->email)]));
});

test('confirms the email from a phone that is not signed in', function () {
    Event::fake([Verified::class]);
    $user = User::factory()->unverified()->create();

    $this->get(confirmationUrl($user))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/EmailConfirmed'));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class);
});

test('sends a signed-in user on to their dashboard after confirming', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(confirmationUrl($user))
        ->assertRedirect(route('dashboard', absolute: false).'?verified=1');
});

test('refuses a link for a different email address', function () {
    $user = User::factory()->unverified()->create();

    $this->get(confirmationUrl($user, 'someone-else@example.com'))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('refuses a link whose signature was tampered with', function () {
    $user = User::factory()->unverified()->create();

    $this->get(confirmationUrl($user).'x')->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('confirms the email for an app that opened the link itself', function () {
    $user = User::factory()->unverified()->create();

    $this->getJson(confirmationUrl($user))
        ->assertOk()
        ->assertJsonPath('message', 'Your email address is confirmed.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});
