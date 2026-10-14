<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response([
            'id' => '500',
            'type' => 'OWNER',
            'firstName' => 'Olivia',
            'lastName' => 'Owner',
            'attachedLeases' => [],
            'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']],
        ]),
        '*/buildings/7*' => Http::response(['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']]),
        '*/buildings*' => Http::response([
            ['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']],
        ]),
        '*/units*' => Http::response([]),
        '*/docs/50/download' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        // Propertyware filters documents by entity, so the fake does too.
        '*/docs*' => fn (Request $request) => Http::response(array_values(array_filter(
            [
                ['id' => 50, 'fileName' => 'Invoice.pdf', 'fileType' => 'application/pdf', 'entityType' => 'WORK_ORDER', 'entityId' => 1, 'publishToOwnerPortal' => true],
                ['id' => 51, 'fileName' => 'Internal.pdf', 'fileType' => 'application/pdf', 'entityType' => 'WORK_ORDER', 'entityId' => 1, 'publishToOwnerPortal' => false],
                ['id' => 52, 'fileName' => 'Statement.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true],
            ],
            fn (array $document): bool => $document['entityType'] === ($request->data()['entityType'] ?? null)
                && $document['entityId'] === (int) ($request->data()['entityId'] ?? 0),
        ))),
        '*/workorders/1' => Http::response(['id' => '1', 'number' => 1001, 'description' => 'Roof repair', 'status' => 'Closed', 'completedDate' => '2026-09-01T10:00:00.000Z', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'ownerCost' => 250.5]),
        '*/workorders/2' => Http::response(['id' => '2', 'description' => 'Hidden from owners', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => false]),
        '*/workorders*' => Http::response([
            ['id' => '1', 'description' => 'Roof repair', 'status' => 'Closed', 'completedDate' => '2026-09-01T10:00:00.000Z', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z'],
            ['id' => '2', 'description' => 'Hidden from owners', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => false, 'lastModifiedDateTime' => '2026-09-11T10:00:00.000Z'],
        ]),
    ]);
});

function apiOwnerAccount(?User $user = null): ClientAccount
{
    $user ??= User::factory()->create();
    Sanctum::actingAs($user);

    return ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
}

test('lists one page of the owner\'s work orders with counts', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.maintenance.index', $account))
        ->assertOk()
        ->assertJsonPath('data.0.id', '1')
        ->assertJsonPath('data.0.property', '12 Elm St Austin, TX')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.counts.completed', 1)
        ->assertDontSee('Hidden from owners');
});

test('rejects an unknown maintenance status filter', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.maintenance.index', [$account, 'status' => 'bogus']))
        ->assertJsonValidationErrors(['status' => 'The selected status is invalid.']);
});

test('shows the owner the cost on their own work order', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.maintenance.show', [$account, '1']))
        ->assertOk()
        ->assertJsonPath('data.ownerCost', 250.5);
});

test('answers 404 for a work order not published to the owner', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.maintenance.show', [$account, '2']))->assertNotFound();
});

test('lists only the attachments published to the owner', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.maintenance.documents.index', [$account, '1']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.fileName', 'Invoice.pdf');
});

test('downloads a published work order attachment', function () {
    $account = apiOwnerAccount();

    $response = $this->get(route('api.v1.accounts.maintenance.documents.show', [$account, '1', '50']));

    $response->assertOk()->assertDownload('Invoice.pdf');
    expect($response->streamedContent())->toBe('%PDF-1.4 fake');
});

test('answers 404 for an attachment not published to the owner', function () {
    $account = apiOwnerAccount();

    $this->get(route('api.v1.accounts.maintenance.documents.show', [$account, '1', '51']))->assertNotFound();
});

test('shows a property with its maintenance and documents', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.properties.show', [$account, 7]))
        ->assertOk()
        ->assertJsonPath('data.address', '12 Elm St')
        ->assertJsonPath('data.maintenance.counts.completed', 1)
        ->assertJsonPath('data.documents.0.fileName', 'Statement.pdf');
});

test('answers 404 for a property outside the owner\'s portfolios', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.properties.show', [$account, 999]))->assertNotFound();
});

test('shows the owner\'s dashboard', function () {
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.dashboard', $account))
        ->assertOk()
        ->assertJsonPath('data.portfolioCount', 1)
        ->assertJsonPath('data.propertyCount', 1)
        // Leased against vacant, for the portfolio summary ring.
        ->assertJsonPath('data.occupancy.total', 1)
        ->assertJsonPath('data.maintenance.counts.completed', 1)
        // The same feeds the portal shows under the fold.
        ->assertJsonPath('data.messages', [])
        ->assertJsonPath('data.documents.status', 'ready')
        ->assertJsonStructure(['data' => ['activity' => [['type', 'id', 'title', 'at', 'url']]]]);
});

test('shows the owner\'s portfolio but answers 404 for a resident account', function () {
    $owner = apiOwnerAccount();
    $resident = ClientAccount::factory()->resident()->for($owner->user)->create(['propertyware_id' => '900']);

    $this->getJson(route('api.v1.accounts.portfolio', $owner))
        ->assertOk()
        ->assertJsonPath('data.occupancy.total', 1);
    $this->getJson(route('api.v1.accounts.portfolio', $resident))->assertNotFound();
});

test('answers 404 for another client\'s account', function () {
    $someoneElses = ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);
    apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.maintenance.index', $someoneElses))->assertNotFound();
});

test('refuses account data until the email is verified', function () {
    $account = apiOwnerAccount(User::factory()->unverified()->create());

    $this->getJson(route('api.v1.accounts.dashboard', $account))
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');
});

test('answers 503 when propertyware is down', function () {
    $this->mock(DocumentLibrary::class)
        ->shouldReceive('pageOrImport')->andThrow(new PropertywareException('Could not reach Propertyware.'));
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.documents.index', $account))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Propertyware data is unavailable right now. Please try again in a moment.');
});

test('gives the app a work order\'s earlier texts only for the owner\'s own work orders', function () {
    config(['services.tx_maintenance.url' => 'https://maintenance.test', 'services.tx_maintenance.token' => 'portal-token']);
    Http::fake(['maintenance.test/*' => Http::response(['data' => [[
        'work_order' => ['id' => 7, 'propertyware_id' => '1', 'number' => '1001', 'title' => 'Roof repair', 'status' => 'Closed'],
        'messages' => [['id' => 31, 'from' => 'staff', 'to_you' => true, 'body' => 'Roofer comes Friday.', 'media' => [], 'sent_at' => '2026-03-02T15:00:00+00:00']],
    ]]])]);
    $account = apiOwnerAccount();

    $this->getJson(route('api.v1.accounts.maintenance.texts', [$account, '1']))
        ->assertOk()
        ->assertJsonPath('data.thread.id', -7)
        ->assertJsonPath('data.messages.0.body', 'Roofer comes Friday.');

    $this->getJson(route('api.v1.accounts.maintenance.texts', [$account, '2']))->assertNotFound();
});
