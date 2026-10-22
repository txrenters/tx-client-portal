<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/buildings/7' => Http::response(['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']]),
        '*/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/workorders*' => Http::response([
            ['id' => '70', 'number' => 1043, 'category' => 'Roofing', 'description' => 'Leaking roof', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true],
        ]),
        // Propertyware filters documents by entity, so the fake does too.
        '*/docs*' => fn (Request $request) => Http::response(array_values(array_filter(
            [
                ['id' => 80, 'fileName' => 'Roof invoice.pdf', 'fileType' => 'application/pdf', 'entityType' => 'WORK_ORDER', 'entityId' => 70, 'publishToOwnerPortal' => true],
                ['id' => 81, 'fileName' => 'Statement.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true],
            ],
            fn (array $document): bool => $document['entityType'] === ($request->data()['entityType'] ?? null)
                && $document['entityId'] === (int) ($request->data()['entityId'] ?? 0),
        ))),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
});

test('leaves work order files off the documents list', function (array $query) {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, ...$query]))
        ->assertOk()
        ->assertJsonMissing(['fileName' => 'Roof invoice.pdf'])
        ->assertJsonPath('meta.unfilteredTotal', 1);
})->with([
    'unfiltered' => [[]],
    'searching its number' => [['search' => '1043']],
    'filtering by it' => [['work_order' => '70']],
]);

test('drops work order files from a documents list kept from before they left it', function () {
    $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();

    cache()->put(
        'propertyware:documents:v2:owner:500',
        ['fetchedAt' => now()->timestamp, 'records' => [
            ['id' => 80, 'fileName' => 'Roof invoice.pdf', 'fileType' => 'application/pdf', 'entityType' => 'WORK_ORDER', 'entityId' => 70, 'publishToOwnerPortal' => true],
            ['id' => 81, 'fileName' => 'Statement.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true],
        ]],
        now()->addDay(),
    );

    $this->getJson(route('api.v1.accounts.documents.index', $this->account))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fileName', 'Statement.pdf');
});

test('names the property a document belongs to', function () {
    $response = $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();

    expect(collect($response->json('data'))->pluck('propertyName', 'fileName')->all())->toEqual([
        'Statement.pdf' => '12 Elm St',
    ]);
});

test('filters documents by property', function (int $property, int $expectedCount) {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'property' => $property]))
        ->assertOk()
        ->assertJsonCount($expectedCount, 'data');
})->with(['the property' => [7, 1], 'another property' => [8, 0]]);

test('lists the properties that have documents, for the filters', function () {
    $this->getJson(route('api.v1.accounts.documents.index', $this->account))
        ->assertOk()
        ->assertJsonPath('meta.properties', [['id' => 7, 'name' => '12 Elm St']])
        ->assertJsonPath('meta.workOrders', []);
});
