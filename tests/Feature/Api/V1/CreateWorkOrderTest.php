<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    $created = ['id' => 555, 'number' => 2001, 'buildingID' => 7, 'portfolioID' => 2, 'description' => 'Kitchen sink is leaking', 'status' => 'Open', 'category' => 'Plumbing', 'publishToTenantPortal' => true, 'publishToOwnerPortal' => true];

    Http::fake([
        '*/contacts/500' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/contacts/900' => Http::response(['id' => '900', 'type' => 'TENANT', 'attachedLeases' => [['id' => 55]], 'attachedPortfolios' => []]),
        '*/buildings*' => Http::response([['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/workorders/555' => Http::response($created),
        '*/workorders*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response($created, 201)
            : Http::response([]),
        '*/docs*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 321, 'entityType' => 'WORK_ORDER', 'entityId' => 555], 201)
            : Http::response([]),
    ]);
});

function workOrderCreates(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/workorders'))
        ->values()
        ->all();
}

function residentAccount(): ClientAccount
{
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    return ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);
}

test('a tenant\'s request is sent to propertyware with their entry permission', function () {
    $account = residentAccount();

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'category' => 'Plumbing',
        'description' => 'Kitchen sink is leaking',
        'location' => 'Kitchen',
        'urgency' => 'urgent',
        'entry' => 'scheduled',
        'entry_date' => now()->addDay()->toDateString(),
        'entry_time' => '14:37',
        'access_notes' => 'Friendly dog in the yard',
    ])->assertCreated()
        ->assertJsonPath('data.id', '555')
        ->assertJsonPath('meta.photosNotAttached', 0);

    expect(workOrderCreates())->toHaveCount(1)
        ->and(workOrderCreates()[0]->data())->toBe([
            'buildingID' => 7,
            'category' => 'Plumbing',
            'description' => "Kitchen sink is leaking\n\nAccess notes: Friendly dog in the yard",
            'specificLocation' => 'Kitchen',
            'priority' => 'HIGH',
            'requestedBy' => 900,
            'type' => 'Service Request',
            'source' => 'Website',
            'searchTag' => 'client-portal',
            'publishToOwnerPortal' => false,
            'publishToTenantPortal' => true,
            'authorizedToEnter' => 'SPECIFIEDTIME',
            'dateToEnter' => now()->addDay()->toDateString(),
            'hourToEnter' => 14,
            'minuteToEnter' => 30,
        ]);
});

test('attaches the request\'s photos to the new work order', function () {
    $account = residentAccount();

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'category' => 'Plumbing',
        'description' => 'Kitchen sink is leaking',
        'urgency' => 'normal',
        'photos' => [UploadedFile::fake()->image('sink.jpg'), UploadedFile::fake()->image('floor.jpg')],
    ])->assertCreated();

    $uploads = Http::recorded()->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/docs'))
        ->map(function (Request $request): array {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return [$query['entityType'], $query['entityId']];
        })
        ->values()
        ->all();
    expect($uploads)->toBe([['Work Order', '555'], ['Work Order', '555']]);
});

test('an owner must choose one of their own properties', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $account = ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '500']);

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'property_id' => 999,
        'category' => 'Plumbing',
        'description' => 'Fence is down',
        'urgency' => 'normal',
    ])->assertJsonValidationErrors(['property_id' => 'Choose which of your properties needs work.']);

    expect(workOrderCreates())->toBeEmpty();
});

test('only offers and accepts client-facing categories', function () {
    $account = residentAccount();

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'category' => 'HOA Violation',
        'description' => 'Not for clients',
        'urgency' => 'normal',
    ])->assertJsonValidationErrors('category');

    $this->getJson(route('api.v1.accounts.maintenance.request-options', $account))
        ->assertOk()
        ->assertJsonFragment(['value' => 'HVAC', 'label' => 'HVAC'])
        ->assertJsonMissing(['value' => 'HOA Violation']);
});

test('a category is sent with propertyware\'s exact spelling, even though requests are trimmed', function () {
    $account = residentAccount();

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'category' => 'HVAC',
        'description' => 'No cold air',
        'urgency' => 'normal',
    ])->assertCreated();

    expect(workOrderCreates()[0]->data()['category'])->toBe('HVAC ');
});

test('the web form sends the tenant to their new request', function () {
    $user = User::factory()->create();
    ClientAccount::factory()->resident()->for($user)->create(['propertyware_id' => '900']);

    $this->actingAs($user)
        ->post(route('resident.maintenance.store'), [
            'category' => 'Plumbing',
            'description' => 'Kitchen sink is leaking',
            'urgency' => 'normal',
            'entry' => 'anytime',
        ])
        ->assertRedirect(route('resident.maintenance.show', 555))
        ->assertInertiaFlash('toast.message', 'Your request was sent.');

    expect(workOrderCreates()[0]->data()['authorizedToEnter'])->toBe('ANYTIME');
});

test('asks staff not to enter unless the tenant says they may', function (array $answer) {
    $account = residentAccount();

    $this->postJson(route('api.v1.accounts.maintenance.store', $account), [
        'category' => 'Plumbing',
        'description' => 'Kitchen sink is leaking',
        'urgency' => 'normal',
        ...$answer,
    ])->assertCreated();

    expect(workOrderCreates()[0]->data()['authorizedToEnter'])->toBe('NO');
})->with([
    'no answer' => [[]],
    'contact me first' => [['entry' => 'no']],
]);
