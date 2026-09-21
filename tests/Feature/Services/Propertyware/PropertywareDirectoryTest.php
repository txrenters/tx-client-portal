<?php

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakePropertyware(array $contact, array $units = [], array $buildings = []): PropertywareDirectory
{
    Http::fake(function (Request $request) use ($contact, $units, $buildings) {
        if (str_contains($request->url(), '/contacts')) {
            return Http::response($contact);
        }

        if (str_contains($request->url(), '/units')) {
            return Http::response($units);
        }

        // Propertyware filters buildings by portfolio server-side.
        $portfolioId = $request->data()['portfolioID'] ?? null;

        return Http::response($portfolioId === null ? $buildings : array_values(array_filter(
            $buildings,
            fn (array $building): bool => (int) $building['portfolioID'] === (int) $portfolioId,
        )));
    });

    return new PropertywareDirectory(
        new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system'),
        cacheSeconds: 0,
    );
}

function residentContact(array $leaseIds = [55], string $id = '900'): array
{
    return [
        'id' => $id,
        'type' => 'TENANT',
        'firstName' => 'Taylor',
        'lastName' => 'Tenant',
        'email' => 'taylor@example.com',
        'attachedLeases' => collect($leaseIds)->map(fn (int $leaseId): array => ['id' => $leaseId, 'name' => 'Lease', 'role' => 'TENANT'])->all(),
        'attachedPortfolios' => [],
    ];
}

test('scopes a resident to the units on their lease', function () {
    $directory = fakePropertyware(residentContact(leaseIds: [55]), units: [
        ['id' => 11, 'leaseID' => 55, 'buildingID' => 7, 'portfolioID' => 1],
        ['id' => 12, 'leaseID' => 66, 'buildingID' => 8, 'portfolioID' => 2],
    ]);

    $account = ClientAccount::factory()->resident()->make(['propertyware_id' => '900']);

    $scope = $directory->scopeFor($account);

    expect($scope->unitIds)->toBe([11])
        ->and($scope->buildingIds)->toBe([7])
        ->and($scope->allowsUnit(11))->toBeTrue()
        ->and($scope->allowsUnit(12))->toBeFalse();
});

test('scopes an owner to the units in their portfolios', function () {
    $directory = fakePropertyware([
        'id' => '500',
        'type' => 'OWNER',
        'firstName' => 'Olivia',
        'lastName' => 'Owner',
        'email' => 'olivia@example.com',
        'attachedLeases' => [],
        'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']],
    ], units: [
        ['id' => 11, 'leaseID' => 55, 'buildingID' => 7, 'portfolioID' => 1],
        ['id' => 12, 'leaseID' => 66, 'buildingID' => 8, 'portfolioID' => 2],
    ]);

    $scope = $directory->scopeFor(ClientAccount::factory()->owner()->make(['propertyware_id' => '500']));

    expect($scope->portfolioIds)->toBe([2])
        ->and($scope->unitIds)->toBe([12])
        ->and($scope->allowsPortfolio(2))->toBeTrue()
        ->and($scope->allowsPortfolio(1))->toBeFalse();
});

test('grants nothing when the contact type does not match the portal', function () {
    $directory = fakePropertyware(residentContact(), units: [
        ['id' => 11, 'leaseID' => 55, 'buildingID' => 7, 'portfolioID' => 1],
    ]);

    $scope = $directory->scopeFor(ClientAccount::factory()->owner()->make(['propertyware_id' => '900']));

    expect($scope->isEmpty())->toBeTrue();
});

test('grants nothing when propertyware has no such contact', function () {
    $directory = fakePropertyware([]);

    $scope = $directory->scopeFor(ClientAccount::factory()->resident()->make(['propertyware_id' => '404']));

    expect($scope->isEmpty())->toBeTrue()
        ->and($scope->role)->toBe(ClientRole::Resident);
});

test('reads the contact and lease links from a propertyware record', function () {
    $directory = fakePropertyware(residentContact(leaseIds: [55, 56]));

    $contact = $directory->findContact('900');

    expect($contact->fullName())->toBe('Taylor Tenant')
        ->and($contact->role)->toBe(ClientRole::Resident)
        ->and($contact->leaseIds)->toBe([55, 56]);
});

test('scopes a resident of a single-family home to the building holding their lease', function () {
    $directory = fakePropertyware(residentContact(leaseIds: [55]), units: [], buildings: [
        ['id' => 7, 'leaseID' => 55, 'portfolioID' => 1],
        ['id' => 8, 'leaseID' => 66, 'portfolioID' => 2],
    ]);

    $scope = $directory->scopeFor(ClientAccount::factory()->resident()->make(['propertyware_id' => '900']));

    expect($scope->buildingIds)->toBe([7])
        ->and($scope->unitIds)->toBe([])
        ->and($scope->allowsWorkOrder(['unitID' => null, 'buildingID' => 7]))->toBeTrue()
        ->and($scope->allowsWorkOrder(['unitID' => null, 'buildingID' => 8]))->toBeFalse();
});

test('scopes an owner to the buildings in their portfolios', function () {
    $directory = fakePropertyware([
        'id' => '500',
        'type' => 'OWNER',
        'firstName' => 'Olivia',
        'lastName' => 'Owner',
        'attachedLeases' => [],
        'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']],
    ], units: [], buildings: [
        ['id' => 7, 'leaseID' => 55, 'portfolioID' => 1],
        ['id' => 8, 'leaseID' => 66, 'portfolioID' => 2],
    ]);

    $scope = $directory->scopeFor(ClientAccount::factory()->owner()->make(['propertyware_id' => '500']));

    expect($scope->buildingIds)->toBe([8]);
});

test('occupancy reuses listed buildings and fetches only missing buildings', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://propertyware.test/buildings?*' => Http::response([
            ['id' => 7, 'portfolioID' => 2, 'leaseID' => 55],
            ['id' => 999, 'portfolioID' => 3, 'leaseID' => 66],
        ]),
        'https://propertyware.test/buildings/8' => Http::response(['id' => 8, 'portfolioID' => 2, 'leaseID' => null]),
    ]);
    $directory = new PropertywareDirectory(new PropertywareClient('https://propertyware.test', 'id', 'secret', 'system'));
    $scope = new ClientScope(ClientRole::Owner, '500', portfolioIds: [2], buildingIds: [7, 8]);

    expect($directory->occupancyFor($scope))->toBe(['total' => 2, 'occupied' => 1, 'vacant' => 1]);
    expect($directory->occupancyFor($scope))->toBe(['total' => 2, 'occupied' => 1, 'vacant' => 1]);

    Http::assertSentCount(2);
});

test('reuses a client\'s cached scope instead of asking propertyware again', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://propertyware.test/contacts/*' => Http::response(residentContact(leaseIds: [55])),
        'https://propertyware.test/units*' => Http::response([['id' => 11, 'leaseID' => 55, 'buildingID' => 7]]),
        'https://propertyware.test/buildings*' => Http::response([]),
    ]);
    $directory = new PropertywareDirectory(new PropertywareClient('https://propertyware.test', 'id', 'secret', 'system'));
    $account = ClientAccount::factory()->resident()->make(['propertyware_id' => '900']);
    $directory->scopeFor($account);
    $sentBefore = Http::recorded()->count();

    $scope = $directory->scopeFor($account);

    expect($scope->role)->toBe(ClientRole::Resident)
        ->and($scope->unitIds)->toBe([11])
        ->and($scope->buildingIds)->toBe([7])
        ->and(Http::recorded()->count())->toBe($sentBefore);
});

test('labels buildings from the cache and fetches only the uncached ones', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://propertyware.test/buildings/7' => Http::response(['id' => 7, 'address' => ['address' => '12 Elm St']]),
        'https://propertyware.test/buildings/8' => Http::response(['id' => 8, 'address' => ['address' => '99 Oak Ave']]),
    ]);
    $directory = new PropertywareDirectory(new PropertywareClient('https://propertyware.test', 'id', 'secret', 'system'));
    $directory->findBuilding(7);

    $addresses = $directory->addressesForBuildings([7, 8, 8]);

    expect($addresses)->toBe([7 => '12 Elm St', 8 => '99 Oak Ave']);
    Http::assertSentCount(2);
});
