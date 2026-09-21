<?php

use App\Enums\ClientRole;
use App\Models\ClientInvitation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('shows who an invitation is for', function () {
    ClientInvitation::factory()->withToken('invite-token')->create(['name' => 'Taylor Tenant', 'email' => 'taylor@example.com']);

    $this->getJson(route('api.v1.invitations.show', 'invite-token'))
        ->assertOk()
        ->assertJsonPath('data.email', 'taylor@example.com')
        ->assertJsonPath('data.role', 'resident')
        ->assertJsonPath('data.hasAccount', false);
});

test('answers 404 for an expired invitation', function () {
    ClientInvitation::factory()->withToken('invite-token')->expired()->create();

    $this->getJson(route('api.v1.invitations.show', 'invite-token'))->assertNotFound();
});

test('creates a verified login from the app and links the invited account', function () {
    $invitation = ClientInvitation::factory()->withToken('invite-token')->owner()->create(['email' => 'olivia@example.com']);

    $response = $this->postJson(route('api.v1.invitations.store', 'invite-token'), [
        'name' => 'Olivia Owner',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'iPhone',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.emailVerified', true)
        ->assertJsonPath('user.accounts.0.role', 'owner');
    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('asks an existing login to sign in before accepting', function () {
    User::factory()->create(['email' => 'olivia@example.com']);
    ClientInvitation::factory()->withToken('invite-token')->create(['email' => 'olivia@example.com']);

    $this->postJson(route('api.v1.invitations.store', 'invite-token'), [
        'name' => 'Olivia Owner',
        'password' => 'password',
        'password_confirmation' => 'password',
        'device_name' => 'iPhone',
    ])->assertConflict();
});

test('links an invitation to the signed-in client who holds that email', function () {
    $user = User::factory()->create(['email' => 'olivia@example.com']);
    ClientInvitation::factory()->withToken('invite-token')->owner()->create(['email' => 'olivia@example.com', 'propertyware_id' => '500']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.invitations.accept', 'invite-token'))
        ->assertCreated()
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.reference', '500');

    expect($user->clientAccountFor(ClientRole::Owner)?->propertyware_id)->toBe('500');
});

test('refuses an invitation sent to a different email', function () {
    Sanctum::actingAs(User::factory()->create(['email' => 'someone@example.com']));
    ClientInvitation::factory()->withToken('invite-token')->create(['email' => 'olivia@example.com']);

    $this->postJson(route('api.v1.invitations.accept', 'invite-token'))->assertForbidden();
});
