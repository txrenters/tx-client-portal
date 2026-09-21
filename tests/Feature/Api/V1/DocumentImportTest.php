<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/workorders*' => Http::response([
            ['id' => '70', 'description' => 'Recent repair', 'status' => 'Closed', 'completedDate' => now()->subMonth()->toIso8601String(), 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'lastModifiedDateTime' => now()->subMonth()->toIso8601String()],
            ['id' => '71', 'description' => 'Old repair', 'status' => 'Closed', 'completedDate' => now()->subYears(3)->toIso8601String(), 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'lastModifiedDateTime' => now()->subYears(3)->toIso8601String()],
            ['id' => '72', 'description' => 'Old but still open', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'lastModifiedDateTime' => now()->subYears(3)->toIso8601String()],
        ]),
        // Uploads (POST) answer with the new document; reads list the existing ones.
        '*/docs*' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response([
                ['id' => 60, 'fileName' => 'Statement.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true],
            ])
            : Http::response(['id' => 321, 'fileName' => 'receipt.pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true], 201),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
});

function documentRequestsFor(string $entityType): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === 'GET' && ($request->data()['entityType'] ?? null) === $entityType)
        ->map(fn (Request $request): int => (int) $request->data()['entityId'])
        ->sort()
        ->values()
        ->all();
}

test('answers "importing" first, then the documents once they have been read', function () {
    $this->withDefer();

    $this->getJson(route('api.v1.accounts.documents.index', $this->account))
        ->assertAccepted()
        ->assertHeader('Retry-After', '3')
        ->assertJsonPath('status', 'importing');

    $this->getJson(route('api.v1.accounts.documents.index', $this->account))
        ->assertOk()
        ->assertJsonPath('data.0.fileName', 'Statement.pdf');
});

test('reads attachments only for open work orders and those updated in the last year', function () {
    $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();

    expect(documentRequestsFor('WORK_ORDER'))->toBe([70, 72]);
});

test('serves the kept list right away and refreshes it in the background', function () {
    $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();
    $this->travel(10)->minutes();
    $sentBefore = count(documentRequestsFor('BUILDING'));

    $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk()->assertJsonPath('data.0.fileName', 'Statement.pdf');

    expect(count(documentRequestsFor('BUILDING')))->toBe($sentBefore + 1);
});

test('adds an uploaded file to the kept list without importing everything again', function () {
    $this->getJson(route('api.v1.accounts.documents.index', $this->account))->assertOk();
    $sentBefore = count(documentRequestsFor('BUILDING'));

    $this->postJson(route('api.v1.accounts.documents.store', $this->account), [
        'file' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf'),
        'property_id' => 7,
    ])->assertCreated();

    $this->getJson(route('api.v1.accounts.documents.index', $this->account))
        ->assertOk()
        ->assertJsonFragment(['fileName' => 'receipt.pdf']);
    expect(count(documentRequestsFor('BUILDING')))->toBe($sentBefore);
});
