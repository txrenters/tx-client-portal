<?php

use App\Models\ClientAccount;
use App\Models\ClientInvitation;
use App\Models\User;
use App\Notifications\ClientInvitationNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Propertyware answers with the contact the invite command verifies.
 */
function fakeContact(string $type = 'OWNER', string $id = '123456'): void
{
    Http::fake(['*' => Http::response(['id' => $id, 'type' => $type, 'firstName' => 'Dana', 'lastName' => 'Owner'])]);
}

test('emails an invitation to a propertyware client', function () {
    Notification::fake();
    fakeContact();

    $this->artisan('client:invite', [
        'email' => 'Owner@Example.com',
        'name' => 'Dana Owner',
        'role' => 'owner',
        'propertyware_id' => '123456',
    ])->assertSuccessful();

    $invitation = ClientInvitation::sole();

    expect($invitation->email)->toBe('owner@example.com')
        ->and($invitation->propertyware_id)->toBe('123456');

    Notification::assertSentOnDemand(
        ClientInvitationNotification::class,
        fn (ClientInvitationNotification $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'owner@example.com'
            && ClientInvitation::hashToken($notification->token) === $invitation->token_hash,
    );
});

test('refuses to invite a propertyware record that is already linked', function () {
    Notification::fake();
    fakeContact();

    ClientAccount::factory()->owner()->create(['propertyware_id' => '123456']);

    $this->artisan('client:invite', [
        'email' => 'owner@example.com',
        'name' => 'Dana Owner',
        'role' => 'owner',
        'propertyware_id' => '123456',
    ])->assertFailed();

    expect(ClientInvitation::count())->toBe(0);
    Notification::assertNothingSent();
});

test('refuses to invite a client to a portal they already have', function () {
    Notification::fake();
    fakeContact('TENANT', '999999');

    $user = User::factory()->resident()->create(['email' => 'resident@example.com']);

    $this->artisan('client:invite', [
        'email' => $user->email,
        'name' => $user->name,
        'role' => 'tenant',
        'propertyware_id' => '999999',
    ])->assertFailed();

    expect(ClientInvitation::count())->toBe(0);
});

test('rejects a role that is not a client portal', function () {
    Notification::fake();

    $this->artisan('client:invite', [
        'email' => 'someone@example.com',
        'name' => 'Someone',
        'role' => 'admin',
        'propertyware_id' => '123456',
    ])->assertFailed();

    expect(ClientInvitation::count())->toBe(0);
});

test('refuses to invite a contact that propertyware does not have', function () {
    Notification::fake();
    Http::fake(['*' => Http::response([])]);

    $this->artisan('client:invite', [
        'email' => 'owner@example.com',
        'name' => 'Dana Owner',
        'role' => 'owner',
        'propertyware_id' => '123456',
    ])->assertFailed();

    expect(ClientInvitation::count())->toBe(0);
    Notification::assertNothingSent();
});

test('refuses to grant the owner portal to a tenant contact', function () {
    Notification::fake();
    fakeContact('TENANT');

    $this->artisan('client:invite', [
        'email' => 'owner@example.com',
        'name' => 'Dana Owner',
        'role' => 'owner',
        'propertyware_id' => '123456',
    ])->assertFailed();

    expect(ClientInvitation::count())->toBe(0);
});

test('invites without checking propertyware when told to skip verification', function () {
    Notification::fake();
    Http::fake(['*' => Http::response([], 500)]);

    $this->artisan('client:invite', [
        'email' => 'owner@example.com',
        'name' => 'Dana Owner',
        'role' => 'owner',
        'propertyware_id' => '123456',
        '--skip-verification' => true,
    ])->assertSuccessful();

    expect(ClientInvitation::count())->toBe(1);
});
