<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false, 'services.propertyware.staff_logins' => ['lmotelet']]);

    $conversation = fn (string $id, string $type, string $entityType, int $entityId, string $subject, array $comments): array => [
        'id' => $id, 'type' => $type, 'subject' => $subject, 'entityType' => $entityType, 'entityID' => $entityId,
        'createdDateTime' => $comments[0][1],
        'comments' => array_map(fn (array $comment): array => ['id' => $id.'-'.md5($comment[0]), 'text' => $comment[0], 'createdBy' => $comment[2] ?? 'lmotelet', 'createdDateTime' => $comment[1]], $comments),
    ];

    $conversations = [
        'contacts/500' => [$conversation('1', 'OWNER', 'Contact', 500, 'Owner draw', [['Would you like to hold the owner draw?', '2026-09-10T10:00:00.000Z']])],
        'contacts/900' => [$conversation('2', 'TENANT', 'Contact', 900, 'Welcome home', [['Welcome to your new home!', '2026-09-01T10:00:00.000Z']])],
        'buildings/7' => [
            $conversation('3', 'OWNER', 'Building', 7, '12 Elm St,', [
                ['The AC is being repaired under work order #1001.', '2026-09-11T10:00:00.000Z', 'ltijerin'],
                ['Repair is done.', '2026-09-12T10:00:00.000Z', 'oowner'],
            ]),
            $conversation('4', 'TENANT', 'Building', 7, 'Your renewal', [['Your lease renewal is ready.', '2026-09-05T10:00:00.000Z']]),
            $conversation('5', 'MANAGEMENT_TEAM', 'Building', 7, 'Call note', [['Tenant called about a locked bank account.', '2026-09-13T10:00:00.000Z']]),
        ],
    ];

    Http::fake([
        // Propertyware filters by type; the internal note is returned regardless, to prove the portal drops it.
        '*/conversations*' => function (Request $request) use ($conversations) {
            preg_match('#/((?:contacts|buildings)/\d+)/conversations#', $request->url(), $match);

            return Http::response(array_values(array_filter(
                $conversations[$match[1]] ?? [],
                fn (array $record): bool => $record['type'] === ($request->data()['type'] ?? null) || $record['type'] === 'MANAGEMENT_TEAM',
            )));
        },
        '*/contacts/500*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Portfolio']]]),
        '*/contacts/900*' => Http::response(['id' => '900', 'type' => 'TENANT', 'attachedLeases' => [['id' => 55, 'name' => 'Lease']], 'attachedPortfolios' => []]),
        '*/buildings/7*' => Http::response(['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX'], 'propertyManagerList' => [['name' => 'ltijerin', 'roleAsString' => 'Property Manager']]]),
        '*/workorders/1' => Http::response([
            'id' => '1', 'number' => 1001, 'description' => 'AC repair', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2,
            'publishToOwnerPortal' => true, 'publishToTenantPortal' => true, 'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z',
        ]),
        '*/buildings*' => Http::response([['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']]]),
        '*/units*' => Http::response([]),
        '*/docs*' => Http::response([]),
        '*/workorders*' => Http::response([[
            'id' => '1', 'number' => 1001, 'description' => 'AC repair', 'status' => 'Open', 'buildingID' => 7, 'portfolioID' => 2,
            'publishToOwnerPortal' => true, 'publishToTenantPortal' => true, 'lastModifiedDateTime' => '2026-09-10T10:00:00.000Z',
        ]]),
    ]);

    $this->owner = User::factory()->create();
    $this->ownerAccount = ClientAccount::factory()->owner()->for($this->owner)->create(['propertyware_id' => '500']);
});

test('shows the owner their conversations, newest first, with mentioned work orders linked', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.conversations.index'), 'client/Conversations', 'conversations')->assertOk();

    expect($response->json('props.conversations.total'))->toBe(2)
        ->and($response->json('props.conversations.results.data.0'))->toMatchArray([
            'subject' => '12 Elm St',
            'about' => 'property',
            'messageCount' => 2,
            'preview' => 'Repair is done.',
            'workOrders' => [['id' => '1', 'number' => '1001']],
        ])
        ->and($response->json('props.conversations.results.data.1'))->toMatchArray(['subject' => 'Owner draw', 'about' => 'account']);
});

test('never shows internal management team notes or the other side\'s conversations', function () {
    $this->actingAs($this->owner);

    partialGet(route('owner.conversations.index'), 'client/Conversations', 'conversations')
        ->assertOk()
        ->assertDontSee('locked bank account')
        ->assertDontSee('Your renewal');

    Http::assertSent(fn (Request $request) => ! str_contains($request->url(), '/conversations') || $request->data()['type'] === 'OWNER');
});

test('narrows conversations by search and by property', function (array $query, array $subjects) {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.conversations.index', $query), 'client/Conversations', 'conversations');

    expect(collect($response->json('props.conversations.results.data'))->pluck('subject')->all())->toBe($subjects);
})->with([
    'search' => [['search' => 'owner draw'], ['Owner draw']],
    'the account itself' => [['property' => 'account'], ['Owner draw']],
    'one property' => [['property' => 7], ['12 Elm St']],
]);

test('shows a property\'s conversations on its page', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.properties.show', 7), 'owner/PropertyDetail', 'conversations')->assertOk();

    expect($response->json('props.conversations.total'))->toBe(1)
        ->and($response->json('props.conversations.conversations.0.subject'))->toBe('12 Elm St');
});

test('residents only see their tenant conversations', function () {
    $tenant = User::factory()->create();
    $account = ClientAccount::factory()->resident()->for($tenant)->create(['propertyware_id' => '900']);
    Sanctum::actingAs($tenant);

    $this->getJson(route('api.v1.accounts.conversations.index', $account))
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.subject', 'Your renewal')
        ->assertJsonPath('data.1.subject', 'Welcome home');
});

test('the app lists conversations and can filter them to one property', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.conversations.index', [$this->ownerAccount, 'property' => 7]))
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.unfilteredTotal', 2)
        ->assertJsonPath('meta.properties.0', ['id' => 7, 'label' => '12 Elm St Austin, TX'])
        ->assertJsonPath('data.0.messages.1.text', 'Repair is done.')
        ->assertJsonPath('data.0.messages.1.author', 'oowner');
});

test('the app cannot read another client\'s conversations', function () {
    $other = ClientAccount::factory()->owner()->create(['propertyware_id' => '501']);
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.conversations.index', $other))->assertNotFound();
});

test('rejects an unknown property filter', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.conversations.index', [$this->ownerAccount, 'property' => 'everything']))
        ->assertJsonValidationErrors('property');
});

test('tells staff messages apart from the client\'s own replies', function (array $staffLogins) {
    config(['services.propertyware.staff_logins' => $staffLogins]);
    Sanctum::actingAs($this->owner);

    $messages = $this->getJson(route('api.v1.accounts.conversations.index', [$this->ownerAccount, 'property' => 7]))->json('data.0.messages');

    expect(array_column($messages, 'fromClient', 'author'))->toBe(['ltijerin' => false, 'oowner' => true]);
})->with([
    'a configured staff login' => [['ltijerin']],
    'the property\'s listed manager' => [[]],
]);

test('shows the conversations that mention a work order on its page', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.maintenance.show', '1'), 'owner/MaintenanceDetail', 'conversations')->assertOk();

    expect(collect($response->json('props.conversations'))->pluck('subject')->all())->toBe(['12 Elm St']);

    Sanctum::actingAs($this->owner);
    $this->getJson(route('api.v1.accounts.maintenance.conversations.index', [$this->ownerAccount, '1']))
        ->assertOk()
        ->assertJsonPath('data.0.subject', '12 Elm St');
});

test('shows account conversations on the Messages page rather than in the sidebar', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.messages.index'), 'client/Messages', 'accountConversations')->assertOk();

    expect($response->json('props.accountConversations.total'))->toBe(1)
        ->and($response->json('props.accountConversations.conversations.0'))->toMatchArray([
            'subject' => 'Owner draw',
            'about' => 'account',
        ]);
});

test('shows a resident the conversations about their home on its page', function () {
    $tenant = User::factory()->create();
    ClientAccount::factory()->resident()->for($tenant)->create(['propertyware_id' => '900']);
    $this->actingAs($tenant);

    $response = partialGet(route('resident.property'), 'resident/Property', 'conversations')->assertOk();

    expect($response->json('props.conversations.total'))->toBe(1)
        ->and($response->json('props.conversations.conversations.0.subject'))->toBe('Your renewal');
});
