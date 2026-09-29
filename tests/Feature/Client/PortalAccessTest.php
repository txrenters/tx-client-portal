<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

test('guests are redirected to the login page', function () {
    $this->get(route('resident.dashboard'))->assertRedirect(route('login'));
});

test('a resident can open the resident dashboard', function () {
    $this->actingAs(User::factory()->resident()->create())
        ->get(route('resident.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('resident/Dashboard'));
});

test('forbids a resident from opening any owner page', function (string $route) {
    $this->actingAs(User::factory()->resident()->create())
        ->get(route($route))
        ->assertForbidden();
})->with([
    'owner dashboard' => 'owner.dashboard',
    'owner maintenance' => 'owner.maintenance.index',
    'owner financials' => 'owner.financials',
]);

test('forbids an owner from opening a resident page', function () {
    $this->actingAs(User::factory()->owner()->create())
        ->get(route('resident.payments'))
        ->assertForbidden();
});

test('sends a client to their own portal from the dashboard', function () {
    $this->actingAs(User::factory()->owner()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('owner.dashboard'));
});

test('returns a client holding both roles to the portal they used last', function () {
    $user = User::factory()->resident()->owner()->create();

    $this->actingAs($user)->get(route('owner.dashboard'))->assertOk();

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('owner.dashboard'));
});

test('explains that an unlinked account is still being set up', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/AccountPending'));
});

test('shares only the portals the client holds', function () {
    $this->actingAs(User::factory()->resident()->create())
        ->get(route('resident.dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('client.roles', ['resident'])
            ->where('client.activeRole', 'resident'),
        );
});

test('shares the Propertyware portal for the role in view, for the sidebar link', function (string $state, string $route, string $portal) {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route($route))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('client.propertywarePortalUrl', "https://app.propertyware.com/pw/portals/houstonpropertymanagement/{$portal}.action"));
})->with([
    'resident' => ['resident', 'resident.dashboard', 'tenant'],
    'owner' => ['owner', 'owner.dashboard', 'owner'],
]);
