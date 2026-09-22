<?php

use App\Jobs\SyncLeaseLedger;
use App\Models\ClientAccount;
use App\Models\LeaseLedgerEntry;
use App\Models\LeaseLedgerSync;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config([
        'services.propertyware.discover_accounts' => false,
        'services.propertyware.soap.url' => 'https://soap.test/api',
        'services.propertyware.soap.username' => 'portal',
        'services.propertyware.soap.password' => 'secret',
    ]);

    Http::fake([
        '*/contacts/*' => Http::response([
            'id' => '900',
            'type' => 'TENANT',
            'attachedLeases' => [['id' => 55, 'name' => 'Lease', 'role' => 'Primary']],
            'attachedPortfolios' => [],
        ]),
        '*/buildings*' => Http::response([['id' => 7, 'leaseID' => 55, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/leases/55' => Http::response(['id' => 55, 'active' => true, 'status' => 'Active', 'baseRent' => 1400.0, 'leaseBalance' => 120.5]),
    ]);

    // Propertyware's consolidated ledger: charges positive, money received
    // negative, plus the internal ids the tenant must never be shown.
    $this->ledger = [
        ['id' => 1, 'leaseID' => 55, 'date' => '2026-09-01', 'amount' => 1400.0, 'glAccountID' => 4000],
        ['id' => 2, 'leaseID' => 55, 'date' => '2026-09-03', 'amount' => -1400.0, 'glAccountID' => 1000, 'refNo' => 'EP-9', 'comments' => 'One Time ePayment received', 'destinationAccountID' => 1234567],
        ['id' => 3, 'leaseID' => 55, 'date' => '2026-08-01', 'amount' => 40.0, 'glAccountID' => 4100],
        // The tenant's own prepaid money being applied to a charge.
        ['id' => 4, 'leaseID' => 55, 'date' => '2026-08-01', 'amount' => -40.0, 'glAccountID' => 2000, 'comments' => 'PREPAY Applied to Charges'],
        // Rent credited back.
        ['id' => 5, 'leaseID' => 55, 'date' => '2026-07-01', 'amount' => -100.0, 'glAccountID' => 4000, 'comments' => 'Rent adjustment'],
    ];

    Http::fake([
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 4000, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => 4100, 'name' => 'Tenant Benefit Package', 'accountType' => 'Income'],
            ['id' => 1000, 'name' => 'Accounts Receivable', 'accountType' => 'A/R'],
            ['id' => 2000, 'name' => 'Prepayments', 'accountType' => 'Current Liability'],
        ]),
        '*/leases/adjustments*' => fn () => Http::response($this->ledger),
    ]);

    $this->tenant = User::factory()->create();
    $this->account = ClientAccount::factory()->resident()->for($this->tenant)->create(['propertyware_id' => '900']);
});

test('shows the tenant what they were charged and what they paid, newest first', function () {
    $this->actingAs($this->tenant);

    $response = partialGet(route('resident.lease'), 'resident/Lease', 'ledger')->assertOk();

    $entries = $response->json('props.ledger.entries');

    expect(collect($entries)->pluck('date')->all())->toBe(['2026-09-03', '2026-09-01', '2026-08-01', '2026-08-01', '2026-07-01'])
        // Money received reads as a payment, not as a minus sign.
        // A payment reads as one, not as the internal account it posts against.
        ->and($entries[0])->toMatchArray(['kind' => 'payment', 'amount' => 1400.0, 'description' => 'Payment', 'detail' => 'One Time ePayment received', 'reference' => 'EP-9'])
        ->and($entries[1])->toMatchArray(['kind' => 'charge', 'amount' => 1400.0, 'description' => 'Rent'])
        // Prepaid money being applied is the tenant's own, on neither side of
        // the totals, and a credit comes off what was charged.
        ->and(collect($entries)->pluck('kind')->all())->toContain('prepayment', 'credit')
        ->and($response->json('props.ledger.charged'))->toEqual(1340.0)
        ->and($response->json('props.ledger.paid'))->toEqual(1400.0)
        // Propertyware's bank details never reach the page.
        ->and(json_encode($entries))->not->toContain('1234567');
});

test('pages the transactions, counting every one in the totals', function () {
    // 30 charges: three pages of ten.
    $this->ledger = collect(range(1, 30))->map(fn (int $day): array => [
        'id' => $day,
        'leaseID' => 55,
        'date' => '2026-01-'.str_pad((string) min($day, 28), 2, '0', STR_PAD_LEFT),
        'amount' => 100.0,
        'glAccountID' => 4000,
    ])->all();

    $this->actingAs($this->tenant);

    $first = partialGet(route('resident.lease'), 'resident/Lease', 'ledger')->assertOk();

    expect($first->json('props.ledger.entries'))->toHaveCount(10)
        ->and($first->json('props.ledger.results.total'))->toBe(30)
        ->and($first->json('props.ledger.results.last_page'))->toBe(3)
        // Every entry counts towards the totals, not just this page.
        ->and($first->json('props.ledger.charged'))->toEqual(3000.0);

    $last = partialGet(route('resident.lease', ['page' => 3]), 'resident/Lease', 'ledger')->assertOk();

    expect($last->json('props.ledger.entries'))->toHaveCount(10)
        ->and($last->json('props.ledger.results.current_page'))->toBe(3);
});

test('the app reads the same ledger with the lease balance', function () {
    Sanctum::actingAs($this->tenant);

    $this->getJson(route('api.v1.accounts.payments', $this->account))
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'payment')
        ->assertJsonPath('meta.charged', 1340)
        ->assertJsonPath('meta.paid', 1400)
        ->assertJsonPath('meta.balance', 120.5);
});

test('owners have no tenant ledger', function () {
    Sanctum::actingAs($this->tenant);
    $owner = ClientAccount::factory()->owner()->for($this->tenant)->create(['propertyware_id' => '500']);

    $this->getJson(route('api.v1.accounts.payments', $owner))->assertNotFound();
});

test('says so when the ledger connection is not set up', function () {
    config(['services.propertyware.client_id' => null, 'services.propertyware.client_secret' => null]);

    $this->actingAs($this->tenant)
        ->get(route('resident.lease'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('resident/Lease')->where('ledgerAvailable', false));
});

test('the old payments address still reaches the lease page', function () {
    $this->actingAs($this->tenant)
        ->get(route('resident.payments'))
        ->assertRedirect('/resident/lease');
});

test('says the history is still coming while the first read runs', function () {
    Queue::fake();
    LeaseLedgerEntry::query()->delete();
    LeaseLedgerSync::query()->delete();

    $this->actingAs($this->tenant);

    $response = partialGet(route('resident.lease'), 'resident/Lease', 'ledger')->assertOk();

    expect($response->json('props.ledger.syncing'))->toBeTrue()
        ->and($response->json('props.ledger.entries'))->toBe([]);

    Queue::assertPushed(SyncLeaseLedger::class);
});
