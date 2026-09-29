<?php

use App\Models\ClientInvitation;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    // The suite turns the check off everywhere else; these tests are about it.
    config([
        'services.propertyware.require_client_email' => true,
        'services.admin_emails' => ['it@texasrenters.com'],
        'inertia.ssr.enabled' => false,
    ]);
});

/**
 * Propertyware answers the contact lookup with these records.
 *
 * @param  array<int, array<string, mixed>>  $contacts
 */
function propertywareContacts(array $contacts, array $vendors = []): void
{
    Http::fake([
        '*/contacts*' => Http::response($contacts),
        '*/vendors*' => Http::response($vendors),
    ]);
}

function signUp(string $email): TestResponse
{
    return test()->post(route('register.store'), [
        'name' => 'Dana Client',
        'email' => $email,
        'password' => 'A-long-passphrase-9',
        'password_confirmation' => 'A-long-passphrase-9',
    ]);
}

test('anyone on a Propertyware contact can create a login', function (string $type) {
    propertywareContacts([['id' => 1, 'type' => $type, 'email' => 'dana@example.com']]);

    signUp('Dana@Example.com')->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'dana@example.com')->exists())->toBeTrue();
})->with(['TENANT', 'OWNER', 'OTHER']);

test('an email Propertyware does not know cannot sign up, and the attempt is logged', function () {
    propertywareContacts([]);

    signUp('stranger@example.com')->assertSessionHasErrors([
        'email' => 'We could not find this email in our records. Please use the email address registered with us in Propertyware. If you are having trouble, text us at (281) 248-8018 for a faster answer.',
    ]);

    expect(User::count())->toBe(0)
        ->and(SecurityEvent::sole()->type)->toBe('unknown_registration');
});

test('a vendor cannot sign up, even when its email is also on a contact', function () {
    propertywareContacts(
        [['id' => 1, 'type' => 'OTHER', 'email' => 'fixit@example.com']],
        [['id' => 9, 'name' => 'Fix It Plumbing', 'email' => 'office@fixit.com', 'alertEmail' => 'FixIt@Example.com']],
    );

    signUp('fixit@example.com')->assertSessionHasErrors('email');

    expect(User::count())->toBe(0);
});

test('a contact whose email only resembles the one typed is not a match', function () {
    // Propertyware does not always honour the email filter, so matching is exact.
    propertywareContacts([['id' => 1, 'type' => 'TENANT', 'email' => 'dana.smith@example.com']]);

    signUp('dana@example.com')->assertSessionHasErrors('email');

    expect(User::count())->toBe(0);
});

test('Propertyware being down refuses the sign-up rather than letting anyone in', function () {
    Http::fake(['*' => Http::failedConnection()]);

    signUp('dana@example.com')->assertSessionHasErrors([
        'email' => 'We cannot check our records right now. Please try again in a few minutes.',
    ]);

    expect(User::count())->toBe(0);
});

test('someone with an invitation waiting, and the owners, skip the lookup', function () {
    Http::fake();
    ClientInvitation::factory()->create(['email' => 'invited@example.com']);

    signUp('invited@example.com')->assertSessionHasNoErrors();
    auth()->logout();
    signUp('it@texasrenters.com')->assertSessionHasNoErrors();

    Http::assertNothingSent();
    expect(User::count())->toBe(2);
});

test('the app applies the same check', function () {
    propertywareContacts([]);

    $this->postJson(route('api.v1.auth.register'), [
        'name' => 'Dana Client',
        'email' => 'stranger@example.com',
        'password' => 'A-long-passphrase-9',
        'password_confirmation' => 'A-long-passphrase-9',
        'device_name' => 'iphone',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(User::count())->toBe(0);
});

test('guessing addresses one after another is slowed down', function () {
    propertywareContacts([]);

    foreach (range(1, 10) as $n) {
        signUp("guess{$n}@example.com");
    }

    signUp('guess11@example.com')->assertSessionHasErrors([
        'email' => 'Too many attempts. Please wait a minute and try again.',
    ]);
});
