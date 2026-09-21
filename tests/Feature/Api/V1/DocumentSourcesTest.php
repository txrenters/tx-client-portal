<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

function fakeDocumentSources(array $contact): void
{
    Http::fake([
        '*/contacts/*' => Http::response($contact),
        '*/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'leaseID' => 55, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/workorders*' => Http::response([]),
        // Propertyware filters documents by entity, so the fake does too.
        '*/docs*' => fn (Request $request) => Http::response(array_values(array_filter(
            [
                ['id' => 90, 'fileName' => 'Q3 owner statement.pdf', 'entityType' => 'PORTFOLIO', 'entityId' => 2, 'publishToOwnerPortal' => true],
                ['id' => 91, 'fileName' => 'Other owner statement.pdf', 'entityType' => 'PORTFOLIO', 'entityId' => 3, 'publishToOwnerPortal' => true],
                ['id' => 92, 'fileName' => 'Owner W-9.pdf', 'entityType' => 'CONTACT', 'entityId' => 500, 'publishToOwnerPortal' => true],
                ['id' => 93, 'fileName' => 'Internal owner notes.pdf', 'entityType' => 'CONTACT', 'entityId' => 500, 'publishToOwnerPortal' => false],
                ['id' => 94, 'fileName' => 'Tenant ID.pdf', 'entityType' => 'CONTACT', 'entityId' => 900, 'publishToTenantPortal' => true],
            ],
            fn (array $document): bool => $document['entityType'] === ($request->data()['entityType'] ?? null)
                && $document['entityId'] === (int) ($request->data()['entityId'] ?? 0),
        ))),
    ]);
}

function documentNamesFor(ClientAccount $account): array
{
    return collect(test()->getJson(route('api.v1.accounts.documents.index', $account))->assertOk()->json('data'))
        ->pluck('category', 'fileName')
        ->sortKeys()
        ->all();
}

test('shows owners the files published on their portfolios and their own contact', function () {
    fakeDocumentSources(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]);
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);

    expect(documentNamesFor($account))->toBe([
        'Owner W-9.pdf' => 'Account',
        'Q3 owner statement.pdf' => 'Portfolio',
    ]);
});

test('shows residents the files published on their own contact but never asks for portfolio files', function () {
    fakeDocumentSources(['id' => '900', 'type' => 'TENANT', 'attachedLeases' => [['id' => 55]], 'attachedPortfolios' => []]);
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $account = ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);

    expect(documentNamesFor($account))->toBe(['Tenant ID.pdf' => 'Account']);
    Http::assertNotSent(fn (Request $request): bool => ($request->data()['entityType'] ?? null) === 'PORTFOLIO');
});
