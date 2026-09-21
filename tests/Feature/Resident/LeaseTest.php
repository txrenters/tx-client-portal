<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response([
            'id' => '900',
            'type' => 'TENANT',
            'firstName' => 'Taylor',
            'lastName' => 'Tenant',
            'attachedLeases' => [['id' => 55, 'name' => 'Lease', 'role' => 'Primary']],
            'attachedPortfolios' => [],
        ]),
        '*/buildings*' => Http::response([
            ['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']],
        ]),
        '*/units*' => Http::response([]),
        '*/leases/55' => Http::response([
            'id' => 55,
            'status' => 'Active - Notice Given',
            'active' => true,
            'startDate' => '2024-08-01',
            'endDate' => '2026-08-04',
            'moveInDate' => '2024-12-31',
            'moveOutDate' => null,
            'scheduleMoveOutDate' => '2026-09-01',
            'noticeGivenDate' => '2026-08-11',
            'baseRent' => 1400.0,
            'leaseBalance' => 0.0,
            'lateFeeRule' => '7.00% of rent charges after due day 3',
            // Never the client's to see: the owner entity and who else signed.
            'location' => 'SSHB21 LLC | 12 Elm St',
            'leaseName' => 'Tenant, T.',
            'contacts' => [['id' => '901', 'name' => 'Other Tenant']],
        ]),
    ]);

    $this->tenant = User::factory()->create();
    $this->account = ClientAccount::factory()->resident()->for($this->tenant)->create(['propertyware_id' => '900']);
});

test('shows the tenant their lease, without the owner or the other signers', function () {
    $this->actingAs($this->tenant);

    $response = partialGet(route('resident.lease'), 'resident/Lease', 'lease')->assertOk();

    expect($response->json('props.lease'))->toMatchArray([
        'id' => 55,
        'status' => 'Active - Notice Given',
        'active' => true,
        'startDate' => '2024-08-01',
        'endDate' => '2026-08-04',
        'moveInDate' => '2024-12-31',
        // The scheduled move-out stands in until they actually leave.
        'moveOutDate' => '2026-09-01',
        'noticeGivenDate' => '2026-08-11',
        'rent' => 1400.0,
        'balance' => 0.0,
        'lateFeeRule' => '7.00% of rent charges after due day 3',
    ])
        ->and($response->json('props.lease'))->not->toHaveKey('location')
        ->and($response->json('props.lease'))->not->toHaveKey('contacts')
        ->and($response->json('props.lease'))->not->toHaveKey('leaseName');
});

test('leads the dashboard with the lease when it is about to end', function () {
    $this->travelTo('2026-08-20');
    $this->actingAs($this->tenant);

    $response = partialGet(route('resident.dashboard'), 'resident/Dashboard', 'lease')->assertOk();

    expect($response->json('props.lease.endsSoon'))->toBeTrue()
        ->and($response->json('props.lease.rent'))->toEqual(1400.0);
});

test('the app reads the same lease, and owners have none', function () {
    Sanctum::actingAs($this->tenant);

    $this->getJson(route('api.v1.accounts.lease', $this->account))
        ->assertOk()
        ->assertJsonPath('data.id', 55)
        ->assertJsonPath('data.rent', 1400)
        ->assertJsonPath('data.endsSoon', true);

    $owner = ClientAccount::factory()->owner()->for($this->tenant)->create(['propertyware_id' => '500']);

    $this->getJson(route('api.v1.accounts.lease', $owner))->assertNotFound();
});
