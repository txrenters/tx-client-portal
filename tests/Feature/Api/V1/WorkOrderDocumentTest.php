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

test('labels work order attachments with their work order number', function () {
    $response = $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();

    $invoice = collect($response->json('data'))->firstWhere('fileName', 'Roof invoice.pdf');
    expect($invoice)->toMatchArray(['category' => 'Maintenance', 'workOrderId' => '70', 'workOrderNumber' => '1043'])
        ->and(collect($response->json('data'))->firstWhere('fileName', 'Statement.pdf')['workOrderNumber'])->toBeNull();
});

test('names the property a document belongs to', function () {
    $response = $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();

    expect(collect($response->json('data'))->pluck('propertyName', 'fileName')->all())->toEqual([
        'Roof invoice.pdf' => '12 Elm St',
        'Statement.pdf' => '12 Elm St',
    ]);
});

test('finds a work order\'s attachments by searching its number', function () {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'search' => '1043']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fileName', 'Roof invoice.pdf');
});

test('filters documents by work order', function () {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'work_order' => '70']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fileName', 'Roof invoice.pdf');
});

test('filters documents by property, including its work order attachments', function (int $property, int $expectedCount) {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'property' => $property]))
        ->assertOk()
        ->assertJsonCount($expectedCount, 'data');
})->with(['the property' => [7, 2], 'another property' => [8, 0]]);

test('lists the properties and work orders that have documents, for the filters', function () {
    $this->getJson(route('api.v1.accounts.documents.index', $this->account))
        ->assertOk()
        ->assertJsonPath('meta.properties', [['id' => 7, 'name' => '12 Elm St']])
        ->assertJsonPath('meta.workOrders', [['id' => '70', 'number' => '1043', 'category' => 'Roofing', 'title' => 'Leaking roof']]);
});
