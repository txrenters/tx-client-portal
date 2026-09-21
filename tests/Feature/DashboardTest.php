<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated clients are sent to their portal', function () {
    $user = User::factory()->resident()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('resident.dashboard'));
});

test('dashboard maintenance finishes with an error state when propertyware scope cannot load', function (string $role, string $component) {
    $user = User::factory()->create();
    ClientAccount::factory()->for($user)->create(['role' => $role]);
    Http::preventStrayRequests();
    Http::fake(['*/contacts/*' => Http::response([], 500)]);
    $this->actingAs($user);

    partialGet(route($role.'.dashboard'), $component, 'maintenance')
        ->assertOk()
        ->assertJsonPath('props.maintenance.status', 'error')
        ->assertJsonPath('props.maintenance.recent', []);

    Http::assertSentCount(2);
})->with([
    ['owner', 'owner/Dashboard'],
    ['resident', 'resident/Dashboard'],
]);

test('dashboard once props are scoped to the selected account', function (string $role, string $prop) {
    $this->freezeTime();
    $user = User::factory()->create();
    $first = ClientAccount::factory()->for($user)->create(['role' => $role]);
    $second = ClientAccount::factory()->for($user)->create(['role' => $role]);
    $key = $role.'-dashboard-'.$first->id.'-'.$prop;
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
    ];
    $this->actingAs($user)->withSession(['client_account:'.$role => $first->id]);

    $this->get(route($role.'.dashboard'), $headers)
        ->assertOk()
        ->assertJsonPath('onceProps.'.$key.'.prop', $prop)
        ->assertJsonPath('onceProps.'.$key.'.expiresAt', now()->addMinutes(5)->timestamp * 1000);

    $headers['X-Inertia-Except-Once-Props'] = $key;
    $this->get(route($role.'.dashboard'), $headers)
        ->assertOk()
        ->assertJsonMissingPath('deferredProps.default');

    $this->withSession(['client_account:'.$role => $second->id])
        ->get(route($role.'.dashboard'), $headers)
        ->assertOk()
        ->assertJsonPath('deferredProps.default.0', $prop)
        ->assertJsonPath('onceProps.'.$role.'-dashboard-'.$second->id.'-'.$prop.'.prop', $prop);
})->with([
    ['owner', 'portfolio'],
    ['resident', 'property'],
]);
