<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Propertyware\PropertywareClient;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => true, 'inertia.ssr.enabled' => false]);
    app()->instance(PropertywareClient::class, new PropertywareClient('https://propertyware.test', 'id', 'secret', 'system'));
    Http::preventStrayRequests();
});

test('existing sessions discover owner and tenant accounts with the same verified email', function () {
    $user = User::factory()->owner()->create(['email' => 'pm@tyssen.com']);
    Http::fake(['https://propertyware.test/contacts*' => Http::response([
        ['id' => 111, 'type' => 'OWNER', 'email' => ' PM@TYSSEN.COM ', 'firstName' => 'Tyssen'],
        ['id' => 222, 'type' => 'TENANT', 'email' => 'pm@tyssen.com', 'firstName' => 'Resident'],
        ['id' => 333, 'type' => 'OWNER', 'email' => 'someone@example.com', 'firstName' => 'Other'],
        ['id' => 444, 'type' => 'VENDOR', 'email' => 'pm@tyssen.com'],
        ['type' => 'OWNER', 'email' => 'pm@tyssen.com'],
    ])]);

    $this->actingAs($user)->get(route('accounts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/SelectAccount')->has('accounts', 3)
            ->where('accounts', fn ($accounts) => collect($accounts)->contains('reference', '111'))
            ->where('accounts.2.portal', 'Resident portal')
            ->has('client.accounts', 3));

    $this->assertDatabaseHas('client_accounts', ['user_id' => $user->id, 'role' => 'resident', 'propertyware_id' => '222']);
    $this->assertDatabaseMissing('client_accounts', ['propertyware_id' => '333']);
    $this->assertDatabaseMissing('client_accounts', ['propertyware_id' => '444']);
    $this->get(route('accounts.index'))->assertOk();
    Http::assertSentCount(1);
});

test('discovery does not transfer records linked to another login', function () {
    $user = User::factory()->owner()->create();
    $other = ClientAccount::factory()->owner()->create(['propertyware_id' => '111']);
    Http::fake(['https://propertyware.test/contacts*' => Http::response([
        ['id' => 111, 'type' => 'OWNER', 'email' => $user->email],
    ])]);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('owner.dashboard'));

    $this->assertDatabaseHas('client_accounts', ['id' => $other->id, 'user_id' => $other->user_id]);
    expect($user->fresh()->clientAccounts)->toHaveCount(1);
    Http::assertSentCount(1);
});

test('unverified emails cannot discover additional accounts', function () {
    $user = User::factory()->unverified()->owner()->create();
    Http::fake(['https://propertyware.test/contacts*' => Http::response([])]);

    $this->actingAs($user)->get(route('dashboard'));

    Http::assertNothingSent();
    expect($user->fresh()->clientAccounts)->toHaveCount(1);
});

test('discovery failure preserves access without repeatedly delaying the session', function () {
    $this->freezeTime();
    $user = User::factory()->owner()->create();
    Http::fake(['https://propertyware.test/contacts*' => Http::failedConnection()]);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('owner.dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('owner.dashboard'));

    expect($user->fresh()->clientAccounts)->toHaveCount(1);
    Http::assertSentCount(2);
});

test('discovery fills missing account labels and does not repeat during the session', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '111', 'label' => null]);
    Http::fake(['https://propertyware.test/contacts*' => Http::response([
        ['id' => 111, 'type' => 'OWNER', 'email' => $user->email, 'firstName' => 'Tyssen'],
        ['id' => 222, 'type' => 'TENANT', 'email' => $user->email, 'firstName' => 'Resident'],
    ])]);

    $this->actingAs($user)->get(route('accounts.index'))->assertOk();
    $this->travel(6)->minutes();
    $this->get(route('accounts.index'))->assertOk();

    expect($user->fresh()->clientAccounts)->toHaveCount(2);
    $this->assertDatabaseHas('client_accounts', ['propertyware_id' => '111', 'label' => 'Tyssen']);
    Http::assertSentCount(1);
});

test('partial page requests do not trigger account discovery', function () {
    $user = User::factory()->owner()->create();
    Http::fake(['https://propertyware.test/contacts*' => Http::response([])]);

    $this->actingAs($user)
        ->get(route('dashboard'), ['X-Inertia-Partial-Data' => 'maintenance'])
        ->assertRedirect(route('owner.dashboard'));

    Http::assertNothingSent();
});
