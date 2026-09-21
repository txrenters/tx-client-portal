<?php

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/500' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/contacts/900' => Http::response(['id' => '900', 'type' => 'TENANT', 'attachedLeases' => [['id' => 55]], 'attachedPortfolios' => []]),
        '*/buildings*' => Http::response([
            ['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']],
            ['id' => 8, 'portfolioID' => 2, 'address' => ['address' => '99 Oak Ave']],
        ]),
        '*/units*' => Http::response([]),
        '*/workorders*' => Http::response([
            ['id' => '70', 'description' => 'Leaking roof', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2, 'publishToOwnerPortal' => true, 'publishToTenantPortal' => true],
        ]),
        // Uploads (POST) and description updates (PUT) answer with the document; reads find none.
        '*/docs*' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response([])
            : Http::response(['id' => 321, 'fileName' => 'signed-renewal.pdf'], $request->method() === 'POST' ? 201 : 200),
    ]);
});

function uploadedPdf(): UploadedFile
{
    return UploadedFile::fake()->create('signed-renewal.pdf', 120, 'application/pdf');
}

function documentWrites(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => in_array($request->method(), ['POST', 'PUT'], true))
        ->values()
        ->all();
}

test('a resident\'s upload goes on their lease and is published back to them', function () {
    $user = User::factory()->create();
    ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);

    $this->actingAs($user)
        ->post(route('resident.documents.store'), ['file' => uploadedPdf(), 'description' => 'Signed renewal'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Document uploaded.');

    [$upload, $describe] = documentWrites();
    parse_str((string) parse_url($upload->url(), PHP_URL_QUERY), $query);
    expect($query)->toBe(['entityType' => 'Lease', 'entityId' => '55', 'publishToOwnerPortal' => 'false', 'publishToTenantPortal' => 'true'])
        ->and(collect($upload->data())->pluck('name')->all())->toBe(['file'])
        ->and($describe->method())->toBe('PUT')
        ->and($describe->url())->toEndWith('/docs/321')
        ->and($describe->data())->toBe(['fileName' => 'signed-renewal.pdf', 'description' => 'Signed renewal']);
});

test('an owner uploads from the app to the property they choose', function () {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.documents.store', $account), ['file' => uploadedPdf(), 'property_id' => 8])
        ->assertCreated()
        ->assertJsonPath('data.id', '321')
        ->assertJsonPath('data.category', 'Property');

    $writes = documentWrites();
    parse_str((string) parse_url($writes[0]->url(), PHP_URL_QUERY), $query);
    expect($query)->toBe(['entityType' => 'Building', 'entityId' => '8', 'publishToOwnerPortal' => 'true', 'publishToTenantPortal' => 'false'])
        ->and($writes)->toHaveCount(1);
});

test('an owner with several properties must choose one they own', function (?int $propertyId) {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.documents.store', $account), array_filter(['file' => uploadedPdf(), 'property_id' => $propertyId]))
        ->assertJsonValidationErrors(['target_id' => 'Choose which of your properties this file is for.']);

    expect(documentWrites())->toBeEmpty();
})->with(['none chosen' => [null], 'someone else\'s' => [999]]);

test('rejects files that are not documents or photos', function () {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.documents.store', $account), ['file' => UploadedFile::fake()->create('script.exe', 10)])
        ->assertJsonValidationErrors(['file' => 'Upload a PDF, photo (JPG, PNG, HEIC) or Word document.']);

    expect(documentWrites())->toBeEmpty();
});

test('rejects files over 20 MB', function () {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.documents.store', $account), ['file' => UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf')])
        ->assertJsonValidationErrors(['file' => 'Files can be up to 20 MB.']);
});

test('tells the resident when propertyware refuses the upload', function () {
    $this->mock(DocumentLibrary::class)
        ->shouldReceive('upload')->andThrow(new PropertywareException('Propertyware returned 403 for [docs].'));
    $user = User::factory()->create();
    ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);

    $this->actingAs($user)
        ->post(route('resident.documents.store'), ['file' => uploadedPdf()])
        ->assertRedirect()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'We could not upload your file right now. Please try again.']);
});

function uploadQuery(): array
{
    parse_str((string) parse_url(documentWrites()[0]->url(), PHP_URL_QUERY), $query);

    return collect($query)->only(['entityType', 'entityId'])->all();
}

test('an owner can attach a file to a portfolio, a work order or their own record', function (array $choice, array $expected) {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.documents.store', $account), ['file' => uploadedPdf(), ...$choice])->assertCreated();

    expect(uploadQuery())->toBe($expected);
})->with([
    'portfolio' => [['attach_to' => 'portfolio', 'target_id' => 2], ['entityType' => 'Portfolio', 'entityId' => '2']],
    'work order' => [['attach_to' => 'work_order', 'target_id' => 70], ['entityType' => 'Work Order', 'entityId' => '70']],
    'their own record' => [['attach_to' => 'account'], ['entityType' => 'Owner', 'entityId' => '500']],
]);

test('a resident can attach a file to their work order or their own record', function (array $choice, array $expected) {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.documents.store', $account), ['file' => uploadedPdf(), ...$choice])->assertCreated();

    expect(uploadQuery())->toBe($expected);
})->with([
    'work order' => [['attach_to' => 'work_order', 'target_id' => 70], ['entityType' => 'Work Order', 'entityId' => '70']],
    'their own record' => [['attach_to' => 'account'], ['entityType' => 'Tenant', 'entityId' => '900']],
]);

test('refuses to attach a file to records that are not the client\'s', function (string $role, array $choice) {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->{$role}()->for($user)->create(['propertyware_id' => $role === 'owner' ? '500' : '900']);
    Sanctum::actingAs($user);

    $this->postJson(route('api.v1.accounts.documents.store', $account), ['file' => uploadedPdf(), ...$choice])
        ->assertJsonValidationErrors('target_id');

    expect(documentWrites())->toBeEmpty();
})->with([
    'another owner\'s portfolio' => ['owner', ['attach_to' => 'portfolio', 'target_id' => 99]],
    'someone else\'s work order' => ['owner', ['attach_to' => 'work_order', 'target_id' => 999]],
    'a lease, for an owner' => ['owner', ['attach_to' => 'lease', 'target_id' => 55]],
    'a portfolio, for a resident' => ['resident', ['attach_to' => 'portfolio', 'target_id' => 2]],
    'another tenant\'s lease' => ['resident', ['attach_to' => 'lease', 'target_id' => 56]],
]);

test('lists what the client may attach uploads to', function () {
    $user = User::factory()->create();
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);
    Sanctum::actingAs($user);

    $this->getJson(route('api.v1.accounts.documents.upload-targets', $account))
        ->assertOk()
        ->assertJsonPath('data.portfolios', [['id' => 2, 'label' => 'Portfolio']])
        ->assertJsonPath('data.workOrders.0', ['id' => 70, 'label' => 'Leaking roof'])
        ->assertJsonCount(2, 'data.properties')
        ->assertJsonPath('data.leases', []);
});
