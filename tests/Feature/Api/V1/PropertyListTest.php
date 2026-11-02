<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/buildings*' => Http::response([
            ['id' => 7, 'portfolioID' => 2, 'leaseID' => 55, 'numberOfBedrooms' => 3, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'postalCode' => '78701']],
            ['id' => 8, 'portfolioID' => 2, 'numberOfBedrooms' => 2, 'neighborhood' => 'Heights', 'address' => ['address' => '99 Oak Ave', 'city' => 'Houston']],
            ['id' => 9, 'portfolioID' => 2, 'leaseID' => 56, 'numberOfBedrooms' => 5, 'address' => ['address' => '5 Pine Rd', 'city' => 'Houston']],
        ]),
        '*/units*' => Http::response([]),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
});

test('filters the owner\'s properties', function (array $query, array $expectedIds) {
    $response = $this->getJson(route('api.v1.accounts.properties.index', [$this->account, ...$query]))->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe($expectedIds);
})->with([
    'vacant' => [['occupancy' => 'vacant'], [8]],
    'occupied' => [['occupancy' => 'occupied'], [7, 9]],
    'city' => [['city' => 'Houston'], [9, 8]],
    'four or more bedrooms' => [['bedrooms' => '4'], [9]],
    'exact bedrooms' => [['bedrooms' => '2'], [8]],
    'search by zip' => [['search' => '78701'], [7]],
    'search by neighborhood' => [['search' => 'heights'], [8]],
]);

test('pages the owner\'s properties with totals for the filters', function () {
    $this->getJson(route('api.v1.accounts.properties.index', $this->account))
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.perPage', 12)
        ->assertJsonPath('meta.counts', ['occupied' => 2, 'vacant' => 1])
        ->assertJsonPath('meta.cities', ['Austin', 'Houston']);
});

test('rejects an unknown occupancy filter', function () {
    $this->getJson(route('api.v1.accounts.properties.index', [$this->account, 'occupancy' => 'sold']))
        ->assertJsonValidationErrors(['occupancy' => 'The selected occupancy is invalid.']);
});

test('shows the owner each property\'s rent and when its lease ends', function () {
    $this->travelTo('2026-11-03 12:00:00');

    Http::fake([
        '*/leases/55' => Http::response(['id' => 55, 'active' => true, 'baseRent' => 1850, 'endDate' => '2027-07-31']),
        '*/leases/56' => Http::response(['id' => 56, 'active' => true, 'baseRent' => '2100.50', 'endDate' => '2026-12-15']),
    ]);

    $rows = collect($this->getJson(route('api.v1.accounts.properties.index', $this->account))->assertOk()->json('data'))->keyBy('id');

    expect($rows[7]['lease'])->toBe(['rent' => 1850, 'endDate' => '2027-07-31', 'moveOutDate' => null, 'endsSoon' => false])
        ->and($rows[9]['lease'])->toBe(['rent' => 2100.5, 'endDate' => '2026-12-15', 'moveOutDate' => null, 'endsSoon' => true])
        // Vacant: no lease to speak of.
        ->and($rows[8]['lease'])->toBeNull()
        ->and($rows[7])->not->toHaveKey('leaseId');
});
