<?php

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('a client can hold several propertyware records in one portal', function () {
    $user = User::factory()->create();
    $first = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '111', 'label' => 'Liu, Ming']);
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '222', 'label' => 'Peng, Peng']);

    $this->actingAs($user)
        ->withSession(['client_account:owner' => $first->id])
        ->get(route('owner.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('client.accounts.0.label', 'Liu, Ming')
            ->where('client.accounts.0.reference', '111')
            ->where('client.accounts.0.active', true)
            ->where('client.accounts.1.label', 'Peng, Peng')
            ->where('client.accounts.1.reference', '222')
            ->where('client.accounts.1.active', false),
        );
});

test('switching account changes which record the portal shows', function () {
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '111', 'label' => 'Liu, Ming']);
    $second = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '222', 'label' => 'Peng, Peng']);

    $this->actingAs($user)
        ->put(route('accounts.update', $second))
        ->assertRedirect(route('owner.dashboard'));

    $this->actingAs($user)
        ->get(route('owner.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('client.accounts.1.active', true)
            ->where('client.accounts.0.active', false),
        );
});

test('returns 404 when switching to an account belonging to someone else', function () {
    $user = User::factory()->owner()->create();
    $otherAccount = ClientAccount::factory()->owner()->create();

    $this->actingAs($user)
        ->put(route('accounts.update', $otherAccount))
        ->assertNotFound();
});

test('links a second propertyware record to an existing login', function () {
    $user = User::factory()->owner()->create();

    expect($user->clientAccountsFor(ClientRole::Owner))->toHaveCount(1);

    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '999']);

    expect($user->refresh()->clientAccountsFor(ClientRole::Owner))->toHaveCount(2);
});

test('login asks clients with duplicate contact names to choose an account', function () {
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '111', 'label' => 'Tyssen Global Management LLC']);
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '222', 'label' => 'Tyssen Global Management LLC']);
    ClientAccount::factory()->owner()->create(['label' => 'Another client']);

    $this->followingRedirects()
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/SelectAccount')
            ->has('accounts', 2)
            ->where('accounts.0.label', 'Tyssen Global Management LLC')
            ->where('accounts.0.reference', '111')
            ->where('accounts.1.label', 'Tyssen Global Management LLC')
            ->where('accounts.1.reference', '222'),
        );
});

test('portal requests require a valid choice when multiple accounts are linked', function (string $role, string $destination) {
    $user = User::factory()->create();
    ClientAccount::factory()->count(2)->for($user)->create(['role' => $role]);
    $other = ClientAccount::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->withSession(['client_account:'.$role => $other->id])
        ->get(route($destination))
        ->assertRedirect(route('accounts.index'));
})->with([
    ['owner', 'owner.dashboard'],
    ['resident', 'resident.dashboard'],
    ['owner', 'owner.properties.index'],
    ['resident', 'resident.maintenance.index'],
]);

test('guests cannot list linked accounts', function () {
    $this->get(route('accounts.index'))->assertRedirect(route('login'));
});

test('clients with one account skip account selection', function () {
    $user = User::factory()->owner()->create();

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertRedirect(route('dashboard'));
});

test('unlinked clients leave account selection for the pending screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->followingRedirects()
        ->get(route('accounts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/AccountPending'));
});

test('selecting an account remembers its portal and account in the session', function () {
    $user = User::factory()->resident()->create();
    $owner = ClientAccount::factory()->owner()->for($user)->create();

    $this->actingAs($user)
        ->put(route('accounts.update', $owner))
        ->assertRedirect(route('owner.dashboard'))
        ->assertSessionHas('client_role', 'owner')
        ->assertSessionHas('client_account:owner', $owner->id);
});
