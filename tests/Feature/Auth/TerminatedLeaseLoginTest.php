<?php

use App\Models\ClientAccount;
use App\Models\SignInEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passkeys\Passkeys;

const LEASE_ENDED = 'Your lease has ended, so this account can no longer sign in. Contact TexasRenters.com for help.';

/**
 * Fake Propertyware with a tenant on the given leases, keyed by lease id => status.
 *
 * @param  array<int, string>  $leases
 */
function fakeTenantLeases(array $leases): void
{
    Http::fake([
        '*/contacts/*' => Http::response([
            'id' => '900',
            'type' => 'TENANT',
            'attachedLeases' => collect($leases)->keys()->map(fn (int $id): array => ['id' => $id, 'name' => 'Lease', 'role' => 'Primary'])->all(),
            'attachedPortfolios' => [],
        ]),
        '*/buildings*' => Http::response([]),
        '*/units*' => Http::response([]),
        ...collect($leases)->mapWithKeys(fn (string $status, int $id): array => [
            "*/leases/{$id}" => Http::response(['id' => $id, 'status' => $status, 'active' => $status !== 'Terminated']),
        ])->all(),
    ]);
}

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false, 'services.propertyware.block_terminated_leases' => true]);

    $this->tenant = User::factory()->create(['email' => 'wood@example.com']);
    ClientAccount::factory()->resident()->for($this->tenant)->create(['propertyware_id' => '900']);
});

test('a resident whose lease is terminated cannot sign in on the web', function () {
    fakeTenantLeases([55 => 'Terminated']);

    $this->post(route('login.store'), ['email' => 'wood@example.com', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => LEASE_ENDED]);

    $this->assertGuest();
    expect(SignInEvent::sole())->event->toBe('blocked')->channel->toBe('web');
});

test('a resident whose lease is terminated cannot get an app token', function () {
    fakeTenantLeases([55 => 'Terminated']);

    $this->postJson(route('api.v1.auth.login'), ['email' => 'wood@example.com', 'password' => 'password', 'device_name' => 'iphone'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => LEASE_ENDED])
        ->assertJsonMissingPath('token');

    expect($this->tenant->tokens()->count())->toBe(0);
});

test('a resident whose lease is terminated cannot sign in with a passkey', function () {
    fakeTenantLeases([55 => 'Terminated']);
    $passkey = $this->tenant->passkeys()->create(['name' => 'iPhone', 'credential_id' => 'abc', 'credential' => []]);

    expect(fn () => Passkeys::allowsLogin(Request::create('/api/v1/auth/passkeys/login', 'POST'), $passkey))
        ->toThrow(LEASE_ENDED);
});

test('a resident still on a lease signs in', function (string $status) {
    fakeTenantLeases([55 => $status]);

    $this->post(route('login.store'), ['email' => 'wood@example.com', 'password' => 'password'])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($this->tenant);
})->with(['Active', 'Active - Notice Given', 'Going MTM', 'Eviction']);

test('a resident who moved to a new lease signs in despite the old one being terminated', function () {
    fakeTenantLeases([54 => 'Terminated', 55 => 'Active']);

    $this->post(route('login.store'), ['email' => 'wood@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($this->tenant);
});

test('a former resident who also owns a home with us still signs in', function () {
    fakeTenantLeases([55 => 'Terminated']);
    ClientAccount::factory()->owner()->for($this->tenant)->create(['propertyware_id' => '800']);

    $this->post(route('login.store'), ['email' => 'wood@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($this->tenant);
});

test('residents are let in when Propertyware cannot be reached', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $this->post(route('login.store'), ['email' => 'wood@example.com', 'password' => 'password']);

    $this->assertAuthenticatedAs($this->tenant);
});

test('a resident already signed in on the web is signed out once their lease is terminated', function () {
    fakeTenantLeases([55 => 'Terminated']);

    $this->actingAs($this->tenant)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', LEASE_ENDED);

    $this->assertGuest();
});

test('an app token issued before the lease was terminated stops working', function () {
    fakeTenantLeases([55 => 'Terminated']);
    $token = $this->tenant->createToken('iphone')->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.me'))
        ->assertUnauthorized()
        ->assertJson(['message' => LEASE_ENDED]);

    expect($this->tenant->tokens()->count())->toBe(0);
});

test('staff can still look at a former resident\'s portal on their behalf', function () {
    fakeTenantLeases([55 => 'Terminated']);
    config(['services.admin_emails' => ['staff@texasrenters.com']]);
    $admin = User::factory()->create(['email' => 'staff@texasrenters.com']);

    $this->actingAs($admin)->post(route('admin.users.impersonate', $this->tenant));

    $this->get(route('dashboard'))->assertRedirect();
    $this->assertAuthenticatedAs($this->tenant);
});

test('a former resident who signs up again is signed out as soon as their account is linked', function () {
    fakeTenantLeases([55 => 'Terminated']);
    $newcomer = User::factory()->create();

    $this->actingAs($newcomer)->get(route('profile.edit'))->assertOk();

    ClientAccount::factory()->resident()->for($newcomer)->create(['propertyware_id' => '901']);

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
    $this->assertGuest();
});
