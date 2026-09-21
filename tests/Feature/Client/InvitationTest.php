<?php

use App\Enums\ClientRole;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('a new client sets a password, gets linked, and lands in their portal', function () {
    $token = Str::random(64);
    $invitation = ClientInvitation::factory()->withToken($token)->create([
        'email' => 'resident@example.com',
        'propertyware_id' => '778899',
    ]);

    $response = $this->post(route('invitations.store', $token), [
        'name' => 'Jamie Rivera',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ]);

    $response->assertRedirect(route('resident.dashboard'));
    $this->assertAuthenticated();

    $user = User::where('email', 'resident@example.com')->sole();

    expect($user->name)->toBe('Jamie Rivera')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasClientRole(ClientRole::Resident))->toBeTrue();

    $this->assertDatabaseHas('client_accounts', [
        'user_id' => $user->id,
        'role' => ClientRole::Resident->value,
        'propertyware_id' => '778899',
    ]);

    expect($invitation->refresh()->accepted_at)->not->toBeNull();
});

test('an existing client gains the second portal without creating another login', function () {
    $user = User::factory()->resident()->create(['email' => 'both@example.com']);
    $token = Str::random(64);
    ClientInvitation::factory()->owner()->withToken($token)->create([
        'email' => 'both@example.com',
    ]);

    $response = $this->actingAs($user)->post(route('invitations.store', $token));

    $response->assertRedirect(route('owner.dashboard'));

    expect(User::count())->toBe(1)
        ->and($user->refresh()->clientRoles()->pluck('value')->all())
        ->toBe([ClientRole::Resident->value, ClientRole::Owner->value]);
});

test('returns 403 when the invitation was sent to a different email', function () {
    $token = Str::random(64);
    ClientInvitation::factory()->withToken($token)->create(['email' => 'invited@example.com']);

    $this->actingAs(User::factory()->create(['email' => 'someone-else@example.com']))
        ->post(route('invitations.store', $token))
        ->assertForbidden();

    $this->assertDatabaseEmpty('client_accounts');
});

test('returns 404 for an expired invitation', function () {
    $token = Str::random(64);
    ClientInvitation::factory()->expired()->withToken($token)->create();

    $this->post(route('invitations.store', $token), [
        'name' => 'Jamie Rivera',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertNotFound();

    $this->assertGuest();
});

test('returns 404 when the invitation was already accepted', function () {
    $token = Str::random(64);
    ClientInvitation::factory()->accepted()->withToken($token)->create();

    $this->post(route('invitations.store', $token), [
        'name' => 'Jamie Rivera',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertNotFound();
});

test('requires a name and password from a new client', function () {
    $token = Str::random(64);
    ClientInvitation::factory()->withToken($token)->create();

    $this->post(route('invitations.store', $token))
        ->assertSessionHasErrors(['name', 'password']);

    $this->assertGuest();
    $this->assertDatabaseEmpty('client_accounts');
});

test('shows the invitation as invalid instead of failing', function () {
    $this->get(route('invitations.show', Str::random(64)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/AcceptInvitation')
            ->where('invitation', null),
        );
});

test('asks an invited client who already has a login to sign in first', function () {
    $token = Str::random(64);
    User::factory()->create(['email' => 'existing@example.com']);
    ClientInvitation::factory()->owner()->withToken($token)->create(['email' => 'existing@example.com']);

    $this->get(route('invitations.show', $token))->assertRedirect(route('login'));
});

test('does not expose the propertyware id to the browser', function () {
    $token = Str::random(64);
    ClientInvitation::factory()->withToken($token)->create(['propertyware_id' => '445566']);

    $this->get(route('invitations.show', $token))
        ->assertOk()
        ->assertDontSee('445566');
});
