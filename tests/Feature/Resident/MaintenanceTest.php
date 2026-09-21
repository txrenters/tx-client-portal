<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::fake([
        '*/contacts/*' => Http::response([
            'id' => '900',
            'type' => 'TENANT',
            'firstName' => 'Taylor',
            'lastName' => 'Tenant',
            'attachedLeases' => [['id' => 55, 'name' => 'Lease', 'role' => 'Primary']],
            'attachedPortfolios' => [],
        ]),
        '*/buildings/7*' => Http::response(['id' => 7, 'name' => 'Olivia Owner - Elm', 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']]),
        '*/buildings*' => Http::response([
            ['id' => 7, 'name' => 'Olivia Owner - Elm', 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']],
            ['id' => 8, 'leaseID' => 66, 'portfolioID' => 3, 'address' => ['address' => '99 Oak Ave']],
        ]),
        '*/units*' => Http::response([]),
        // Propertyware filters documents by entity, so the fake does too.
        '*/docs*' => fn (Request $request) => Http::response(array_values(array_filter(
            [
                ['id' => 50, 'fileName' => 'Invoice.pdf', 'fileType' => 'application/pdf', 'entityType' => 'WORK_ORDER', 'entityId' => 1, 'publishToOwnerPortal' => true, 'publishToTenantPortal' => false],
                ['id' => 52, 'fileName' => 'Photo.jpg', 'fileType' => 'image/jpeg', 'entityType' => 'WORK_ORDER', 'entityId' => 1, 'publishToOwnerPortal' => false, 'publishToTenantPortal' => true],
            ],
            fn (array $document): bool => $document['entityType'] === ($request->data()['entityType'] ?? null)
                && $document['entityId'] === (int) ($request->data()['entityId'] ?? 0),
        ))),
        '*/workorders/1' => Http::response([
            'id' => '1',
            'number' => '1001',
            'description' => 'AC not cooling',
            'status' => 'Open',
            'buildingID' => 7,
            'portfolioID' => 2,
            'publishToTenantPortal' => true,
            'createdDateTime' => '2026-09-01T10:00:00.000Z',
            'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z',
            'lease' => 'Taylor Tenant Lease',
            'requestedBy' => 'Olivia Owner',
            'ownerCost' => 250.5,
            'assignedVendors' => [['id' => 5, 'companyName' => 'Acme Plumbing', 'phone' => '555-0100', 'email' => 'dispatch@example.com']],
        ]),
        '*/workorders/3' => Http::response([
            'id' => '3',
            'description' => 'Internal note',
            'status' => 'Open',
            'buildingID' => 7,
            'portfolioID' => 2,
            'publishToTenantPortal' => false,
        ]),
        '*/workorders/999' => Http::response([
            'id' => '999',
            'description' => 'Another home',
            'status' => 'Open',
            'buildingID' => 8,
            'portfolioID' => 3,
            'publishToTenantPortal' => true,
        ]),
        '*/workorders*' => Http::response([
            ['id' => '1', 'description' => 'AC not cooling', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToTenantPortal' => true, 'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z'],
            ['id' => '2', 'description' => 'Another tenant leak', 'status' => 'Open', 'buildingID' => 8, 'portfolioID' => 3, 'publishToTenantPortal' => true, 'lastModifiedDateTime' => '2026-09-11T10:00:00.000Z'],
            ['id' => '3', 'description' => 'Internal note', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToTenantPortal' => false, 'lastModifiedDateTime' => '2026-09-12T10:00:00.000Z'],
        ]),
    ]);
});

function linkedResident(): User
{
    $user = User::factory()->create();
    ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);

    return $user;
}

test('shows only the requests for the tenant\'s own home', function () {
    $this->actingAs(linkedResident());

    partialGet(route('resident.maintenance.index'), 'resident/Maintenance', 'maintenance')
        ->assertOk()
        ->assertJsonPath('props.maintenance.counts.open', 1)
        ->assertJsonPath('props.maintenance.workOrders.data.0.id', '1')
        ->assertJsonPath('props.maintenance.workOrders.data.0.title', 'AC not cooling');
});

test('filters the tenant\'s requests by status on the server', function () {
    $this->actingAs(linkedResident());

    partialGet(route('resident.maintenance.index', ['status' => 'completed']), 'resident/Maintenance', 'maintenance')
        ->assertJsonPath('props.maintenance.counts.open', 1)
        ->assertJsonPath('props.maintenance.workOrders.total', 0)
        ->assertJsonCount(0, 'props.maintenance.workOrders.data');
});

test('rejects an unknown status filter', function () {
    $this->actingAs(linkedResident())
        ->get(route('resident.maintenance.index', ['status' => 'bogus']))
        ->assertSessionHasErrors(['status' => 'The selected status is invalid.']);
});

test('does not send another client\'s work order to the browser', function () {
    $this->actingAs(linkedResident());

    partialGet(route('resident.maintenance.index'), 'resident/Maintenance', 'maintenance')
        ->assertDontSee('Another tenant leak')
        ->assertDontSee('Internal note');
});

test('defers the maintenance list so the page renders first', function () {
    $this->actingAs(linkedResident())
        ->get(route('resident.maintenance.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('resident/Maintenance')
            ->missing('maintenance'),
        );
});

test('shows the tenant\'s property on their dashboard', function () {
    $this->actingAs(linkedResident());

    partialGet(route('resident.dashboard'), 'resident/Dashboard', 'property')
        ->assertOk()
        ->assertJsonPath('props.property.address', '12 Elm St')
        ->assertJsonPath('props.property.fullAddress', '12 Elm St Austin, TX');
});

test('returns 403 when the login has no resident link', function () {
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create();

    $this->actingAs($user)->get(route('resident.maintenance.index'))->assertForbidden();
});

test('opens the tenant\'s own request with its timeline', function () {
    $this->actingAs(linkedResident())
        ->get(route('resident.maintenance.show', '1'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('resident/MaintenanceDetail')
            ->where('workOrder.title', 'AC not cooling')
            ->where('workOrder.timeline.0.label', 'Request received')
            ->where('workOrder.ownerCost', null)
            ->where('workOrder.vendors.0.name', 'Acme Plumbing')
            ->where('workOrder.vendors.0.phone', null)
            ->where('workOrder.vendors.0.email', null),
        );
});

test('returns 404 for a request belonging to another client', function () {
    $this->actingAs(linkedResident())
        ->get(route('resident.maintenance.show', '999'))
        ->assertNotFound();
});

test('returns 404 for a work order not published to the resident portal', function () {
    $this->actingAs(linkedResident())
        ->get(route('resident.maintenance.show', '3'))
        ->assertNotFound();
});

test('shows the resident their property page', function () {
    $this->actingAs(linkedResident());

    partialGet(route('resident.property'), 'resident/Property', 'property')
        ->assertOk()
        ->assertJsonPath('props.property.address', '12 Elm St');
});

test('hides an owner-only attachment from the resident', function () {
    $this->actingAs(linkedResident());

    partialGet(route('resident.maintenance.show', '1'), 'resident/MaintenanceDetail', 'documents')
        ->assertOk()
        ->assertJsonCount(1, 'props.documents')
        ->assertJsonPath('props.documents.0.fileName', 'Photo.jpg')
        ->assertDontSee('Invoice.pdf');
});

test('returns 404 when a resident downloads an owner-only attachment', function () {
    $this->actingAs(linkedResident())
        ->get(route('resident.maintenance.documents.download', ['workOrder' => '1', 'document' => 50]))
        ->assertNotFound();
});

test('never shows the tenant the owner\'s name on a work order', function () {
    $this->actingAs(linkedResident())
        ->get(route('resident.maintenance.show', '1'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workOrder.requestedBy', null)
            ->where('workOrder.leaseName', 'Taylor Tenant Lease'),
        )
        ->assertDontSee('Olivia Owner');
});

test('never shows the tenant the building name, which can carry the owner\'s name', function () {
    $this->actingAs(linkedResident());

    partialGet(route('resident.property'), 'resident/Property', 'property')
        ->assertOk()
        ->assertJsonPath('props.property.name', null)
        ->assertJsonPath('props.property.address', '12 Elm St')
        ->assertDontSee('Olivia Owner');
});
