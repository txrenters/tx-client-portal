<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::fake([
        '*/contacts/*' => Http::response([
            'id' => '500',
            'type' => 'OWNER',
            'firstName' => 'Olivia',
            'lastName' => 'Owner',
            'attachedLeases' => [],
            'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']],
        ]),
        '*/buildings/7*' => Http::response(['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX'], 'status' => 'Occupied', 'numberOfBedrooms' => 3]),
        '*/buildings*' => Http::response([
            ['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']],
        ]),
        '*/units*' => Http::response([]),
        '*/docs/50/download' => Http::response('%PDF-1.4 fake', 200, ['Content-Type' => 'application/pdf']),
        // Propertyware filters documents by entity, so the fake does too.
        '*/docs*' => fn (Request $request) => Http::response(array_values(array_filter(
            [
                ['id' => 50, 'fileName' => 'Invoice.pdf', 'fileType' => 'application/pdf', 'entityType' => 'WORK_ORDER', 'entityId' => 1, 'publishToOwnerPortal' => true, 'publishToTenantPortal' => false, 'createdDateTime' => '2026-09-02T10:00:00.000Z'],
                ['id' => 51, 'fileName' => 'Internal.pdf', 'fileType' => 'application/pdf', 'entityType' => 'WORK_ORDER', 'entityId' => 1, 'publishToOwnerPortal' => false, 'publishToTenantPortal' => false],
                ['id' => 52, 'fileName' => 'Statement.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 7, 'publishToOwnerPortal' => true, 'createdDateTime' => '2026-09-03T10:00:00.000Z'],
                ['id' => 54, 'fileName' => 'Lease addendum.pdf', 'fileType' => 'application/pdf', 'entityType' => 'LEASE', 'entityId' => 55, 'publishToOwnerPortal' => true, 'createdDateTime' => '2026-09-04T10:00:00.000Z'],
                ['id' => 55, 'fileName' => 'Tenant only.pdf', 'fileType' => 'application/pdf', 'entityType' => 'LEASE', 'entityId' => 55, 'publishToOwnerPortal' => false, 'publishToTenantPortal' => true],
                ['id' => 53, 'fileName' => 'OtherProperty.pdf', 'fileType' => 'application/pdf', 'entityType' => 'BUILDING', 'entityId' => 99, 'publishToOwnerPortal' => true],
            ],
            fn (array $document): bool => $document['entityType'] === ($request->data()['entityType'] ?? null)
                && $document['entityId'] === (int) ($request->data()['entityId'] ?? 0),
        ))),
        '*/workorders/1' => Http::response([
            'id' => '1',
            'number' => 1001,
            'category' => 'Plumbing',
            'assignedVendors' => [['id' => 5, 'companyName' => 'Acme Plumbing', 'phone' => '555-0100']],
            'description' => 'Roof repair',
            'status' => 'Closed',
            'completedDate' => '2026-09-01T10:00:00.000Z',
            'buildingID' => 7,
            'portfolioID' => 2,
            'publishToOwnerPortal' => true,
            'lease' => 'Tina Tenant Lease',
            'requestedBy' => 'Tina Tenant',
            'ownerCost' => 250.5,
            'createdDateTime' => '2026-08-01T10:00:00.000Z',
        ]),
        '*/workorders*' => Http::response([
            ['id' => '1', 'description' => 'Roof repair', 'status' => 'Closed', 'completedDate' => '2026-09-01T10:00:00.000Z', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z'],
            ['id' => '2', 'description' => 'Hidden from owners', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => false, 'lastModifiedDateTime' => '2026-09-11T10:00:00.000Z'],
        ]),
    ]);
});

function linkedOwner(): User
{
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);

    return $user;
}

test('shows portfolio maintenance to the owner', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.maintenance.index', ['status' => 'all']), 'owner/Maintenance', 'maintenance')
        ->assertOk()
        ->assertJsonPath('props.maintenance.counts.completed', 1)
        ->assertJsonPath('props.maintenance.workOrders.data.0.property', '12 Elm St Austin, TX')
        ->assertDontSee('Hidden from owners');
});

test('asks propertyware only for the owner\'s portfolio', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.maintenance.index'), 'owner/Maintenance', 'maintenance');

    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/workorders')
        || ($request->data()['portfolioID'] ?? null) === 2,
    );
});

test('counts the owner\'s properties on their dashboard', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.dashboard'), 'owner/Dashboard', 'portfolio')
        ->assertOk()
        ->assertJsonPath('props.portfolio.propertyCount', 1)
        ->assertJsonPath('props.portfolio.properties.0.address', '12 Elm St');
});

test('leads the dashboard with the newest documents and merges them into recent activity', function () {
    $this->actingAs(linkedOwner());

    $feeds = partialGet(route('owner.dashboard'), 'owner/Dashboard', 'documents', 'activity', 'messages')->assertOk();

    // Newest first; the work order's invoice shows only on the work order.
    expect($feeds->json('props.documents.status'))->toBe('ready')
        ->and(collect($feeds->json('props.documents.items'))->pluck('fileName')->all())
        ->toBe(['Lease addendum.pdf', 'Statement.pdf'])
        // The hub is not configured in tests, so there are no messages to merge.
        ->and($feeds->json('props.messages'))->toBe([]);

    $activity = collect($feeds->json('props.activity'));

    expect($activity->pluck('type')->unique()->all())->toBe(['maintenance', 'document'])
        ->and($activity->first())->toMatchArray(['type' => 'maintenance', 'id' => '1'])
        ->and($activity->first()['url'])->toBe(route('owner.maintenance.show', '1'))
        ->and($activity->pluck('at')->all())->toBe($activity->pluck('at')->sortDesc()->values()->all());
});

test('shows the owner their portfolio overview', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.portfolio'), 'owner/Portfolio', 'portfolio')
        ->assertOk()
        ->assertJsonPath('props.portfolio.occupancy.total', 1)
        ->assertJsonPath('props.portfolio.occupancy.occupied', 1)
        ->assertJsonPath('props.portfolio.properties.0.address', '12 Elm St');
});

test('lists the owner\'s properties', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.properties.index'), 'owner/Properties', 'properties')
        ->assertOk()
        ->assertJsonPath('props.properties.results.data.0.id', 7);
});

test('opens one of the owner\'s properties with its details', function () {
    $this->actingAs(linkedOwner())
        ->get(route('owner.properties.show', 7))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/PropertyDetail')
            ->where('property.address', '12 Elm St'),
        );
});

test('returns 404 for a property the owner does not hold', function () {
    $this->actingAs(linkedOwner())
        ->get(route('owner.properties.show', 999))
        ->assertNotFound();
});

test('shows the owner the cost on their own request', function () {
    $this->actingAs(linkedOwner())
        ->get(route('owner.maintenance.show', '1'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('owner/MaintenanceDetail')
            ->where('workOrder.ownerCost', 250.5),
        );
});

test('includes the work order number and vendor on the detail page', function () {
    $this->actingAs(linkedOwner())
        ->get(route('owner.maintenance.show', '1'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workOrder.number', '1001')
            ->where('workOrder.category', 'Plumbing')
            ->where('workOrder.vendors.0.name', 'Acme Plumbing')
            ->where('workOrder.vendors.0.phone', '555-0100'),
        );
});

test('lists the attachments published to the owner on a work order', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.maintenance.show', '1'), 'owner/MaintenanceDetail', 'documents')
        ->assertOk()
        ->assertJsonPath('props.documents.0.fileName', 'Invoice.pdf')
        ->assertJsonCount(1, 'props.documents')
        ->assertDontSee('Internal.pdf');
});

test('downloads a work order attachment the owner may see', function () {
    $response = $this->actingAs(linkedOwner())
        ->get(route('owner.maintenance.documents.download', ['workOrder' => '1', 'document' => 50]));

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('attachment');
});

test('returns 404 for a work order attachment not published to the owner portal', function () {
    $this->actingAs(linkedOwner())
        ->get(route('owner.maintenance.documents.download', ['workOrder' => '1', 'document' => 51]))
        ->assertNotFound();
});

test('returns 404 for an attachment on a work order the owner cannot open', function () {
    $this->actingAs(linkedOwner())
        ->get(route('owner.maintenance.documents.download', ['workOrder' => '999', 'document' => 50]))
        ->assertNotFound();
});

test('shows the property\'s documents on the property page, but not its work orders\' files', function () {
    $this->actingAs(linkedOwner());

    $response = partialGet(route('owner.properties.show', 7), 'owner/PropertyDetail', 'documents')->assertOk();

    expect(collect($response->json('props.documents.results.data'))->pluck('fileName')->sort()->values()->all())
        ->toBe(['Lease addendum.pdf', 'Statement.pdf']);
    $response->assertDontSee('OtherProperty.pdf')->assertDontSee('Tenant only.pdf');
});

test('gives the app the property\'s history, newest first', function () {
    linkedOwner();

    $account = ClientAccount::where('propertyware_id', '500')->sole();
    Sanctum::actingAs($account->user);
    $response = $this->getJson(route('api.v1.accounts.properties.show', [$account, 7]))->assertOk();

    expect(collect($response->json('data.history'))->map(fn (array $event): string => $event['type'].':'.($event['detail'] ?? ''))->all())->toBe([
        'document_added:Lease addendum.pdf',
        'document_added:Statement.pdf',
        'work_order_completed:Roof repair',
    ]);
    $response->assertDontSee('Hidden from owners');
});

test('never shows the owner a tenant\'s name on a work order', function () {
    $this->actingAs(linkedOwner())
        ->get(route('owner.maintenance.show', '1'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workOrder.leaseName', null)
            ->where('workOrder.requestedBy', null),
        )
        ->assertDontSee('Tina Tenant');
});

test('shows the owner their details on file in settings', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('details-on-file'), 'settings/DetailsOnFile', 'contactDetails')
        ->assertOk()
        ->assertJsonPath('props.contactDetails.0.title', 'Contact')
        ->assertJsonPath('props.contactDetails.0.items.0', ['label' => 'Name', 'value' => 'Olivia Owner']);
});

test('the app shows the client their details on file', function () {
    $owner = linkedOwner();
    Sanctum::actingAs($owner);

    $this->getJson(route('api.v1.accounts.contact-details', $owner->clientAccounts->first()))
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Contact')
        ->assertJsonPath('data.0.items.0', ['label' => 'Name', 'value' => 'Olivia Owner']);
});

test('there are no details on file for a login without a client account', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('details-on-file'))
        ->assertInertia(fn ($page) => $page->where('hasClientAccount', false)->where('contactDetails', null));
});

test('opens the maintenance page on open work orders', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.maintenance.index'), 'owner/Maintenance', 'maintenance', 'filters')
        ->assertOk()
        ->assertJsonPath('props.filters.status', 'open')
        ->assertJsonPath('props.maintenance.workOrders.total', 0)
        ->assertJsonPath('props.maintenance.counts.completed', 1);
});

test('shows every status when the All tab is chosen', function () {
    $this->actingAs(linkedOwner());

    partialGet(route('owner.maintenance.index', ['status' => 'all']), 'owner/Maintenance', 'maintenance', 'filters')
        ->assertJsonPath('props.filters.status', null)
        ->assertJsonPath('props.maintenance.workOrders.total', 1);
});
