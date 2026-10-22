<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    $files = [
        ['id' => 1, 'fileName' => '221 Knox St.Lease_Renewal-signed.pdf'],
        ['id' => 2, 'fileName' => 'Copy_of_Owner\'s_Property_Statement_(01-01-2024_-_12-31-2024).pdf'],
        ['id' => 3, 'fileName' => '13669GardenGrove#273_GLReport_2012.pdf'],
        ['id' => 4, 'fileName' => '3435 Silverside Dr_Johnson.pdf', 'description' => 'Form 1099'],
        ['id' => 5, 'fileName' => 'Anthony Martin W-9 request for Taxpayer.pdf'],
        ['id' => 6, 'fileName' => 'HVAC invoice.pdf'],
        ['id' => 7, 'fileName' => 'occupied_inspection_10-28-2025.pdf', 'description' => 'Occupied Inspection 10/28/2025'],
    ];

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/workorders*' => Http::response([]),
        '*/docs*' => fn (Request $request) => Http::response(($request->data()['entityType'] ?? null) === 'BUILDING'
            ? array_map(fn (array $file): array => [...$file, 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true], $files)
            : []),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
});

test('sorts each document into a kind by its name and description', function () {
    $response = $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();

    expect(collect($response->json('data'))->pluck('kind', 'id')->sortKeys()->all())->toBe([
        1 => 'leases',
        2 => 'statements',
        3 => 'statements',
        4 => 'tax',
        5 => 'tax',
        6 => 'invoices',
        7 => 'other',
    ])->and($response->json('meta.kindCounts'))->toBe(['leases' => 1, 'statements' => 2, 'tax' => 2, 'invoices' => 1, 'other' => 1]);
});

test('filters documents by kind', function () {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'kind' => 'tax']))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.unfilteredTotal', 7);
});

test('rejects an unknown kind', function () {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'kind' => 'photos']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kind');
});
