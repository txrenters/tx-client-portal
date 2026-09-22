<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
});

test('an admin can open the dashboard', function () {
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Dashboard')->has('stats'));
});

test('the allowlist ignores the case the address was typed in', function () {
    $admin = User::factory()->create(['email' => 'STAFF@texasrenters.com']);

    $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
});

test('a signed-in client cannot reach any admin page', function (string $routeName) {
    $client = User::factory()->create(['email' => 'resident@example.com']);

    $this->actingAs($client)->get(route($routeName))->assertForbidden();
})->with([
    'admin.dashboard',
    'admin.users.index',
    'admin.integrations.index',
    'admin.activity',
]);

test('a client cannot act on another account through the admin routes', function () {
    $client = User::factory()->create(['email' => 'resident@example.com']);
    $victim = User::factory()->create();

    $this->actingAs($client)
        ->post(route('admin.users.password-reset', $victim))
        ->assertForbidden();

    $this->actingAs($client)
        ->post(route('admin.users.revoke-app-sessions', $victim))
        ->assertForbidden();

    $this->actingAs($client)
        ->post(route('admin.users.relink-accounts', $victim))
        ->assertForbidden();
});

test('a guest is sent to log in rather than told the page exists', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('nobody is an admin when the allowlist is empty', function () {
    config(['services.admin_emails' => []]);

    $this->actingAs(User::factory()->create(['email' => 'staff@texasrenters.com']))
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('the log viewer is gated by the same allowlist', function () {
    $client = User::factory()->create(['email' => 'resident@example.com']);
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);

    expect(Gate::forUser($client)->allows('viewLogViewer'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewLogViewer'))->toBeTrue();
});

test('an admin lands on the admin dashboard', function () {
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));
});

test('an admin who is also a client keeps their portals', function () {
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);
    ClientAccount::factory()->owner()->for($admin)->create();

    // Landing somewhere else must not lock them out of the portal itself.
    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('admin.dashboard'));

    $this->actingAs($admin)
        ->get(route('owner.dashboard'))
        ->assertOk();
});

test('a client still lands on their own portal', function () {
    $client = User::factory()->create(['email' => 'resident@example.com']);
    ClientAccount::factory()->resident()->for($client)->create();

    $this->actingAs($client)
        ->get(route('dashboard'))
        ->assertRedirect(route('resident.dashboard'));
});
