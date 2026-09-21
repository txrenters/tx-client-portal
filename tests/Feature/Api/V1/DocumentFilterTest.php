<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/docs*' => Http::response([
            ['id' => 60, 'fileName' => 'August statement.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true, 'createdDateTime' => '2026-08-15T10:00:00.000Z'],
            ['id' => 61, 'fileName' => 'Roof photo.jpg', 'fileType' => 'image/jpeg', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true, 'createdDateTime' => '2026-09-05T10:00:00.000Z'],
            ['id' => 62, 'fileName' => 'Undated.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true],
            // 9pm on the 18th in Texas, already the 19th in UTC.
            ['id' => 63, 'fileName' => 'Late evening.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true, 'createdDateTime' => '2026-09-19T02:00:00.000Z'],
        ]),
    ]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
});

test('filters the account\'s documents by date, type and search', function (array $query, array $expectedFiles) {
    $response = $this->getJson(route('api.v1.accounts.documents.index', [$this->account, ...$query]))->assertOk();

    expect(collect($response->json('data'))->pluck('fileName')->sort()->values()->all())->toBe($expectedFiles);
})->with([
    'from a date' => [['from' => '2026-09-01'], ['Late evening.pdf', 'Roof photo.jpg']],
    'up to a date, inclusive' => [['to' => '2026-08-15'], ['August statement.pdf']],
    'file type' => [['type' => 'pdf'], ['August statement.pdf', 'Late evening.pdf', 'Undated.pdf']],
    'search' => [['search' => 'roof'], ['Roof photo.jpg']],
    // Dates are days in Texas: an evening file belongs to that evening's date.
    'a Texas evening, not the next UTC day' => [['from' => '2026-09-18', 'to' => '2026-09-18'], ['Late evening.pdf']],
    'the next day excludes it' => [['from' => '2026-09-19'], []],
]);

test('rejects a date range that ends before it starts', function () {
    $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'from' => '2026-09-10', 'to' => '2026-09-01']))
        ->assertJsonValidationErrors(['to' => 'The to field must be a date after or equal to from.']);
});

test('pages through the account\'s documents with totals for the whole list', function () {
    $response = $this->getJson(route('api.v1.accounts.documents.index', [$this->account, 'page' => 1]))->assertOk();

    expect($response->json('meta'))->toMatchArray([
        'currentPage' => 1,
        'lastPage' => 1,
        'perPage' => 24,
        'total' => 4,
        'unfilteredTotal' => 4,
        'categories' => ['Property'],
        'typeCounts' => ['images' => 1, 'pdf' => 3, 'other' => 0],
    ]);
});
