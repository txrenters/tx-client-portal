<?php

use App\Enums\StaffRole;
use App\Models\ClientAccount;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
});

function staffWithOwnerAccount(): array
{
    $staff = User::factory()->create(['staff_role' => StaffRole::Staff]);
    $account = ClientAccount::factory()->owner()->for($staff)->create();

    return [$staff, $account];
}

test('staff land on the admin dashboard by default', function () {
    [$staff] = staffWithOwnerAccount();

    $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
});

test('staff who pick one of their client accounts stay on the client side', function () {
    [$staff, $account] = staffWithOwnerAccount();

    $this->actingAs($staff)->put(route('accounts.update', $account))->assertRedirect(route('owner.dashboard'));

    $this->get(route('dashboard'))->assertRedirect(route('owner.dashboard'));
});

test('opening the admin area puts staff back on the staff side', function () {
    [$staff, $account] = staffWithOwnerAccount();

    $this->actingAs($staff)->put(route('accounts.update', $account));
    $this->get(route('admin.help-requests.index'))->assertOk();

    $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
});

test('staff with no client account always go to the admin dashboard', function () {
    $staff = User::factory()->create(['staff_role' => StaffRole::Staff]);

    $this->actingAs($staff)
        ->withSession(['portal_side' => 'client'])
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});

test('the pages know which side staff are on', function () {
    [$staff, $account] = staffWithOwnerAccount();

    $this->actingAs($staff)->get(route('admin.help-requests.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.portalSide', 'staff'));

    $this->put(route('accounts.update', $account));

    $this->get(route('help'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.portalSide', 'client'));
});

test('clients are always on the client side', function () {
    $client = User::factory()->create();
    $account = ClientAccount::factory()->resident()->for($client)->create();

    $this->actingAs($client)->get(route('dashboard'))->assertRedirect(route('resident.dashboard'));
    $this->get(route('help'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.portalSide', 'client'));
});
