<?php

use App\Enums\ClientRole;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function workOrderRecord(array $overrides = []): array
{
    return [
        'id' => '1',
        'number' => '1001',
        'description' => "The AC is not cooling\nsecond line",
        'status' => 'Open',
        'type' => 'Service Request',
        'unitID' => null,
        'buildingID' => 7,
        'portfolioID' => 2,
        'publishToTenantPortal' => true,
        'publishToOwnerPortal' => true,
        'createdDateTime' => '2026-09-01T10:00:00.000Z',
        'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z',
        'scheduledEndDate' => null,
        'completedDate' => null,
        ...$overrides,
    ];
}

function maintenanceFor(array $workOrders, array $building = ['id' => 7, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX', 'postalCode' => '78701']]): MaintenanceSummary
{
    Http::fake([
        '*/workorders*' => Http::response($workOrders),
        '*/buildings/*' => Http::response($building),
        '*/buildings*' => Http::response([$building]),
        '*/units*' => Http::response([]),
    ]);

    $client = new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system');

    return new MaintenanceSummary($client, new PropertywareDirectory($client, cacheSeconds: 0), cacheSeconds: 0);
}

function residentScope(): ClientScope
{
    return new ClientScope(role: ClientRole::Resident, contactId: '900', leaseIds: [55], buildingIds: [7]);
}

test('groups a tenant\'s requests by status and labels them with the address', function () {
    $maintenance = maintenanceFor([
        workOrderRecord(['id' => '1']),
        workOrderRecord(['id' => '2', 'scheduledEndDate' => '2026-09-20T10:00:00.000Z']),
        workOrderRecord(['id' => '3', 'status' => 'Closed', 'completedDate' => '2026-09-05T10:00:00.000Z']),
    ]);

    $result = $maintenance->groups(residentScope());

    expect($result['status'])->toBe('ready')
        ->and($result['counts'])->toBe(['open' => 1, 'scheduled' => 1, 'completed' => 1, 'canceled' => 0])
        ->and($result['groups']['open'][0]['title'])->toBe('The AC is not cooling')
        ->and($result['groups']['open'][0]['property'])->toBe('12 Elm St Austin, TX 78701');
});

test('hides work orders staff have not published to the resident portal', function () {
    $maintenance = maintenanceFor([
        workOrderRecord(['id' => '1', 'publishToTenantPortal' => false]),
        workOrderRecord(['id' => '2', 'publishToTenantPortal' => true]),
    ]);

    $result = $maintenance->groups(residentScope());

    expect($result['counts']['open'])->toBe(1)
        ->and($result['groups']['open'][0]['id'])->toBe('2');
});

test('hides requests staff have not published to the owner portal', function () {
    $maintenance = maintenanceFor([
        workOrderRecord(['id' => '1', 'publishToOwnerPortal' => false]),
    ]);

    $scope = new ClientScope(role: ClientRole::Owner, contactId: '500', portfolioIds: [2], buildingIds: [7]);

    expect($maintenance->groups($scope)['counts']['open'])->toBe(0);
});

test('drops a request that belongs to another client', function () {
    $maintenance = maintenanceFor([
        workOrderRecord(['id' => '1', 'buildingID' => 999, 'unitID' => 999]),
    ]);

    expect($maintenance->groups(residentScope())['counts']['open'])->toBe(0);
});

test('asks propertyware only for the buildings the resident lives in', function () {
    $maintenance = maintenanceFor([workOrderRecord()]);

    $maintenance->forClient(residentScope());

    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/workorders')
        || ($request->data()['buildingID'] ?? null) === 7,
    );
});

test('asks propertyware only for the portfolios the owner holds', function () {
    $maintenance = maintenanceFor([workOrderRecord()]);

    $maintenance->forClient(new ClientScope(role: ClientRole::Owner, contactId: '500', portfolioIds: [2], buildingIds: [7]));

    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/workorders')
        || ($request->data()['portfolioID'] ?? null) === 2,
    );
});

test('sends no request for a client with no linked property', function () {
    $maintenance = maintenanceFor([workOrderRecord()]);

    expect($maintenance->forClient(new ClientScope(role: ClientRole::Resident, contactId: '900')))->toBeEmpty();

    Http::assertNothingSent();
});

test('reports an error instead of failing when propertyware is down', function () {
    Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

    $client = new PropertywareClient('https://api.propertyware.test/v1', 'id', 'secret', 'system');
    $maintenance = new MaintenanceSummary($client, new PropertywareDirectory($client, cacheSeconds: 0), cacheSeconds: 0);

    expect($maintenance->groups(residentScope()))->toBe(['status' => 'error', 'groups' => [], 'counts' => []])
        ->and($maintenance->summary(residentScope()))->toBe(['status' => 'error', 'counts' => [], 'recent' => []]);
});

test('summarises the six most recently updated requests', function () {
    $maintenance = maintenanceFor(collect(range(1, 7))
        ->map(fn (int $day) => workOrderRecord(['id' => (string) $day, 'lastModifiedDateTime' => sprintf('2026-09-%02dT10:00:00.000Z', $day)]))
        ->all());

    $summary = $maintenance->summary(residentScope());

    expect($summary['counts']['open'])->toBe(7)
        ->and(collect($summary['recent'])->pluck('id')->all())->toBe(['7', '6', '5', '4', '3', '2']);
});

test('keeps canceled requests out of scheduled and completed work', function (string $status) {
    $maintenance = maintenanceFor([
        workOrderRecord(['status' => $status, 'scheduledEndDate' => '2022-10-06']),
    ]);

    $result = $maintenance->groups(residentScope());

    expect($result['counts'])->toBe(['open' => 0, 'scheduled' => 0, 'completed' => 0, 'canceled' => 1])
        ->and($result['groups']['canceled'][0]['statusLabel'])->toBe('Canceled');
})->with(['Canceled By Tenant', 'Canceled', 'Cancelled']);

test('ignores a date propertyware stores in an unparseable format', function () {
    $maintenance = maintenanceFor([
        workOrderRecord(['id' => '1', 'dateToEnter' => '2015-09-16T10:00 PM']),
    ]);

    $result = $maintenance->groups(residentScope());

    expect($result['status'])->toBe('ready')
        ->and($result['groups']['open'][0]['entryDate'])->toBeNull();
});

test('large owner accounts use scoped requests instead of the failing company wide list', function () {
    Http::preventStrayRequests();
    Http::fake(['*/workorders*' => function (Request $request) {
        if (! isset($request['portfolioID'])) {
            return Http::response(['error' => 'Broken unrelated record'], 500);
        }

        return Http::response([workOrderRecord(['id' => (string) $request['portfolioID'], 'portfolioID' => (int) $request['portfolioID']])]);
    }]);
    $client = new PropertywareClient('https://propertyware.test', 'id', 'secret', 'system');
    $maintenance = new MaintenanceSummary($client, new PropertywareDirectory($client));
    $scope = new ClientScope(ClientRole::Owner, '500', portfolioIds: range(1, 7), buildingIds: [7]);

    expect($maintenance->forClient($scope))->toHaveCount(7);

    Http::assertSentCount(7);
    Http::assertNotSent(fn (Request $request) => ! isset($request['portfolioID']));
});

test('sends one page of a large request list with counts for the whole list', function () {
    $maintenance = maintenanceFor(collect(range(1, 30))
        ->map(fn (int $id): array => workOrderRecord([
            'id' => (string) $id,
            'lastModifiedDateTime' => sprintf('2026-08-%02dT10:00:00.000Z', $id),
        ]))
        ->all());

    $result = $maintenance->paginate(residentScope(), [], page: 2);

    expect($result['total'])->toBe(30)
        ->and($result['counts']['open'])->toBe(30)
        ->and($result['workOrders']['total'])->toBe(30)
        ->and($result['workOrders']['last_page'])->toBe(2)
        ->and(array_column($result['workOrders']['data'], 'id'))->toBe(['5', '4', '3', '2', '1']);
});

test('filters requests by status, property and search before paging', function (array $filters, array $expectedIds) {
    $maintenance = maintenanceFor([
        workOrderRecord(['id' => '1', 'number' => '1001', 'description' => 'Leaking sink']),
        workOrderRecord(['id' => '2', 'number' => '1002', 'description' => 'Broken fence', 'status' => 'Closed', 'completedDate' => '2026-09-05T10:00:00.000Z']),
    ]);

    $result = $maintenance->paginate(residentScope(), $filters);

    expect(array_column($result['workOrders']['data'], 'id'))->toBe($expectedIds)
        ->and($result['total'])->toBe(2);
})->with([
    'status' => [['status' => 'completed'], ['2']],
    'search by description' => [['search' => 'SINK'], ['1']],
    'search by number' => [['search' => '1002'], ['2']],
    'property' => [['property' => '12 Elm St Austin, TX 78701'], ['1', '2']],
    'unknown property' => [['property' => '1 Nowhere Rd'], []],
]);

test('lists the client\'s property addresses for the property filter', function () {
    $maintenance = maintenanceFor([workOrderRecord()]);

    expect($maintenance->paginate(residentScope(), [])['properties'])->toBe(['12 Elm St Austin, TX 78701']);
});

test('looks up only the address of the property being viewed', function () {
    $maintenance = maintenanceFor([
        workOrderRecord(['id' => '1', 'buildingID' => 7]),
        workOrderRecord(['id' => '2', 'buildingID' => 8]),
    ]);
    $scope = new ClientScope(role: ClientRole::Resident, contactId: '900', leaseIds: [55], buildingIds: [7, 8]);

    $result = $maintenance->groupsForBuilding($scope, 7);

    expect(array_column($result['groups']['open'], 'id'))->toBe(['1']);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/buildings/8'));
});
