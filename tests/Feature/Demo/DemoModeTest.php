<?php

use App\Enums\ClientRole;
use App\Http\Middleware\DemoMode;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Demo\DemoData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

test('a demo login\'s app request is answered by the demo', function () {
    [$user, $account] = demoLogin(ClientRole::Owner);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.accounts.properties.index', $account))->assertOk()
        ->assertJsonFragment(['id' => DemoData::HOME_VACANT]);

    expect(Cache::getDefaultDriver())->toBe(DemoMode::cacheStore());
    Http::assertNotSent(fn ($request) => ! str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
});

test('a guest is left alone', function () {
    $this->get(route('resident.lease'))->assertRedirect();

    expect(Cache::getDefaultDriver())->not->toBe(DemoMode::cacheStore());
});

test('a demo login\'s web page is answered by the demo', function () {
    [$user] = demoLogin(ClientRole::Resident);

    $this->actingAs($user);
    partialGet(route('resident.lease'), 'resident/Lease', 'lease')->assertOk()
        ->assertJsonPath('props.lease.rent', (int) DemoData::RENT);
});

test('a real login still reads Propertyware, with the real cache and account discovery', function () {
    config([
        'services.propertyware.base_url' => 'https://pw.test',
        'services.propertyware.client_id' => 'id', 'services.propertyware.client_secret' => 'secret', 'services.propertyware.system_id' => 'sys',
        'services.propertyware.discover_accounts' => true,
    ]);
    Http::fake([
        'pw.test/contacts?*' => Http::response([]),
        'pw.test/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'P']]]),
        'pw.test/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        'pw.test/units*' => Http::response([]),
    ]);
    $user = User::factory()->create();
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.me'))->assertOk();
    $this->getJson(route('api.v1.accounts.properties.index', $account))->assertOk()->assertJsonFragment(['id' => 7]);

    expect(Cache::getDefaultDriver())->not->toBe(DemoMode::cacheStore());
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://pw.test/contacts?'));
    Http::assertNotSent(fn ($request) => str_ends_with((string) parse_url($request->url(), PHP_URL_HOST), '.demo.invalid'));
});

test('a demo login never goes looking for more Propertyware accounts', function () {
    [$user] = demoLogin(ClientRole::Owner);
    config(['services.propertyware.discover_accounts' => true]);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.me'))->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'contacts?email'));
    expect($user->clientAccounts()->count())->toBe(1);
});
