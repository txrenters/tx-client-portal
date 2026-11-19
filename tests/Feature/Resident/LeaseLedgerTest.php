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
    $this->payments = [];

    Http::fake([
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 4000, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => 4100, 'name' => 'Tenant Benefit Package', 'accountType' => 'Income'],
            ['id' => 1000, 'name' => 'Accounts Receivable', 'accountType' => 'A/R'],
            ['id' => 2000, 'name' => 'Prepayments', 'accountType' => 'Current Liability'],
        ]),
        '*/leases/adjustments*' => fn () => Http::response($this->ledger),
        // Read only for when each payment was deposited.
        '*/leases/payments*' => fn () => Http::response($this->payments),
    ]);

    $this->tenant = User::factory()->create();
    $this->account = ClientAccount::factory()->resident()->for($this->tenant)->create(['propertyware_id' => '900']);
});

test('shows the tenant what they were charged and what they paid, newest first', function () {
    $this->actingAs($this->tenant);

    $response = partialGet(route('resident.lease', ['range' => 'all']), 'resident/Lease', 'ledger')->assertOk();

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

    $first = partialGet(route('resident.lease', ['range' => 'all']), 'resident/Lease', 'ledger')->assertOk();

    expect($first->json('props.ledger.entries'))->toHaveCount(10)
        ->and($first->json('props.ledger.results.total'))->toBe(30)
        ->and($first->json('props.ledger.results.last_page'))->toBe(3)
        // Every entry counts towards the totals, not just this page.
        ->and($first->json('props.ledger.charged'))->toEqual(3000.0);

    $last = partialGet(route('resident.lease', ['range' => 'all', 'page' => 3]), 'resident/Lease', 'ledger')->assertOk();

    expect($last->json('props.ledger.entries'))->toHaveCount(10)
        ->and($last->json('props.ledger.results.current_page'))->toBe(3);
});

test('narrows the transactions and their totals to the period the tenant picks', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $this->actingAs($this->tenant);

    $response = partialGet(route('resident.lease', ['range' => 'last-month', 'from' => '2026-09-01', 'to' => '2026-09-30']), 'resident/Lease', 'ledger')->assertOk();

    expect(collect($response->json('props.ledger.entries'))->pluck('date')->all())->toBe(['2026-09-03', '2026-09-01'])
        ->and($response->json('props.ledger.results.total'))->toBe(2)
        ->and($response->json('props.ledger.charged'))->toEqual(1400.0)
        ->and($response->json('props.ledger.paid'))->toEqual(1400.0)
        ->and($response->json('props.ledger.from'))->toBe('2026-09-01')
        ->and($response->json('props.ledger.to'))->toBe('2026-09-30')
        // The chart still covers the year, whatever the list shows.
        ->and($response->json('props.ledger.months'))->toHaveCount(12);

    $this->actingAs($this->tenant)
        ->get(route('resident.lease', ['range' => 'last-month', 'from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertInertia(fn ($page) => $page->where('filters', ['range' => 'last-month', 'from' => '2026-09-01', 'to' => '2026-09-30']));
});

test('opens on the month the tenant is in, and shows all of it when asked', function () {
    $this->travelTo('2026-09-15 12:00:00');
    $this->actingAs($this->tenant);

    $thisMonth = partialGet(route('resident.lease'), 'resident/Lease', 'ledger', 'filters')->assertOk();

    expect($thisMonth->json('props.filters'))->toBe(['range' => 'this-month', 'from' => '2026-09-01', 'to' => '2026-09-30'])
        ->and(collect($thisMonth->json('props.ledger.entries'))->pluck('date')->all())->toBe(['2026-09-03', '2026-09-01']);

    $allTime = partialGet(route('resident.lease', ['range' => 'all']), 'resident/Lease', 'ledger')->assertOk();

    expect($allTime->json('props.ledger.results.total'))->toBe(5);
});

test('the app still reads the whole tenancy when it names no period', function () {
    $this->travelTo('2026-09-15 12:00:00');
    Sanctum::actingAs($this->tenant);

    $this->getJson(route('api.v1.accounts.payments', $this->account))
        ->assertOk()
        ->assertJsonPath('meta.total', 5);
});

test('the app narrows the ledger to a period too, and refuses one that ends before it starts', function () {
    Sanctum::actingAs($this->tenant);

    $this->getJson(route('api.v1.accounts.payments', ['account' => $this->account, 'from' => '2026-08-01', 'to' => '2026-08-31']))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.charged', 40)
        ->assertJsonPath('meta.paid', 0)
        ->assertJsonPath('meta.from', '2026-08-01');

    $this->getJson(route('api.v1.accounts.payments', ['account' => $this->account, 'from' => '2026-09-01', 'to' => '2026-08-01']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

test('tells the tenant which payments have settled and which are still pending', function () {
    $this->travelTo('2026-09-21 12:00:00');
    $this->ledger[] = ['id' => 6, 'leaseID' => 55, 'date' => '2026-09-18', 'amount' => -200.0, 'glAccountID' => 1000];
    $this->ledger[] = ['id' => 7, 'leaseID' => 55, 'date' => '2026-09-20', 'amount' => -300.0, 'glAccountID' => 1000];
    $this->payments = [
        ['id' => 2, 'leaseID' => 55, 'depositDate' => '2026-09-05'],
        // Not deposited yet, and deposited only tomorrow: both still on their way.
        ['id' => 6, 'leaseID' => 55, 'depositDate' => null],
        ['id' => 7, 'leaseID' => 55, 'depositDate' => '2026-09-22'],
    ];
    $this->actingAs($this->tenant);

    $entries = collect(partialGet(route('resident.lease', ['range' => 'all']), 'resident/Lease', 'ledger')->assertOk()->json('props.ledger.entries'))->keyBy('id');
    $byExternalId = LeaseLedgerEntry::query()->pluck('id', 'external_id');

    expect($entries[$byExternalId['2']])->toMatchArray(['settled' => true, 'settledOn' => '2026-09-05'])
        ->and($entries[$byExternalId['6']])->toMatchArray(['settled' => false, 'settledOn' => null])
        ->and($entries[$byExternalId['7']])->toMatchArray(['settled' => false, 'settledOn' => null])
        // Charges and prepaid money carry no status.
        ->and($entries[$byExternalId['1']])->toMatchArray(['settled' => null, 'settledOn' => null])
        ->and($entries[$byExternalId['4']])->toMatchArray(['settled' => null, 'settledOn' => null]);
});

test('the app sees whether each payment has settled', function () {
    $this->payments = [['id' => 2, 'leaseID' => 55, 'depositDate' => '2026-09-05']];
    Sanctum::actingAs($this->tenant);

    $this->getJson(route('api.v1.accounts.payments', $this->account))
        ->assertOk()
        ->assertJsonPath('data.0.kind', 'payment')
        ->assertJsonPath('data.0.settled', true)
        ->assertJsonPath('data.0.settledOn', '2026-09-05');
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

test('totals what was charged and paid month by month for the last year', function () {
    $this->travelTo('2026-10-15 12:00:00');
    $this->actingAs($this->tenant);

    $months = collect(partialGet(route('resident.dashboard'), 'resident/Dashboard', 'ledger')->assertOk()->json('props.ledger.months'));

    expect($months)->toHaveCount(12)
        ->and($months->first()['month'])->toBe('2025-11')
        // A month where nothing moved still holds its place on the chart.
        ->and($months->last())->toMatchArray(['month' => '2026-10', 'charged' => 0, 'paid' => 0])
        ->and($months->firstWhere('month', '2026-09'))->toMatchArray(['charged' => 1400, 'paid' => 1400])
        // Prepaid money being applied counts on neither side.
        ->and($months->firstWhere('month', '2026-08'))->toMatchArray(['charged' => 40, 'paid' => 0])
        // A credit comes off what was charged that month.
        ->and($months->firstWhere('month', '2026-07'))->toMatchArray(['charged' => -100, 'paid' => 0]);

    Sanctum::actingAs($this->tenant);

    $this->getJson(route('api.v1.accounts.payments', $this->account))
        ->assertOk()
        ->assertJsonCount(12, 'meta.months')
        ->assertJsonPath('meta.months.10', ['month' => '2026-09', 'charged' => 1400, 'paid' => 1400]);
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

/**
 * The rows of a downloaded CSV, without the byte-order mark that tells Excel it is UTF-8.
 *
 * @return array<int, array<int, string>>
 */
function downloadedCsvRows(string $csv): array
{
    expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue();

    return array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
}

test('the tenant downloads the ledger for the period on the page as a spreadsheet', function () {
    $this->actingAs($this->tenant);

    $response = $this->get(route('resident.lease.download', ['format' => 'csv', 'from' => '2026-08-01', 'to' => '2026-09-30']))
        ->assertOk()
        ->assertDownload('Ledger 2026-08-01 to 2026-09-30 - 12 Elm St.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect(downloadedCsvRows($response->streamedContent()))->toBe([
        ['Date', 'Type', 'Description', 'Details', 'Reference', 'Amount', 'Charged', 'Paid'],
        // Oldest first; the July credit is outside the period.
        ['2026-08-01', 'Charge', 'Tenant Benefit Package', '', '', '40.00', '40.00', ''],
        // Prepaid money being applied counts on neither side, as on the page.
        ['2026-08-01', 'Prepayment applied', 'Prepayment', 'PREPAY Applied to Charges', '', '40.00', '', ''],
        ['2026-09-01', 'Charge', 'Rent', '', '', '1400.00', '1400.00', ''],
        ['2026-09-03', 'Payment', 'Payment', 'One Time ePayment received', 'EP-9', '1400.00', '', '1400.00'],
        ['', 'Total', '', '', '', '', '1440.00', '1400.00'],
    ]);
});

test('the tenant downloads the ledger as a PDF', function () {
    $this->actingAs($this->tenant);

    $response = $this->get(route('resident.lease.download', ['format' => 'pdf', 'from' => '2026-07-01', 'to' => '2026-09-30']))
        ->assertOk()
        ->assertDownload('Ledger 2026-07-01 to 2026-09-30 - 12 Elm St.pdf')
        ->assertHeader('Content-Type', 'application/pdf');

    expect(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue();
});

test('a downloaded spreadsheet keeps text that looks like a formula as plain text', function () {
    $this->ledger[1]['comments'] = '=HYPERLINK("https://example.test","Pay here")';
    $this->actingAs($this->tenant);

    $rows = downloadedCsvRows($this->get(route('resident.lease.download', ['format' => 'csv', 'range' => 'all']))->streamedContent());

    expect(collect($rows)->firstWhere(1, 'Payment')[3])->toBe('\'=HYPERLINK("https://example.test","Pay here")');
});

test('the app downloads the same ledger, and only for a resident account', function () {
    Sanctum::actingAs($this->tenant);
    $owner = ClientAccount::factory()->owner()->for($this->tenant)->create(['propertyware_id' => '500']);

    $response = $this->get(route('api.v1.accounts.payments.download', [$this->account, 'format' => 'csv', 'from' => '2026-09-01', 'to' => '2026-09-30']))
        ->assertOk()
        ->assertDownload('Ledger 2026-09-01 to 2026-09-30 - 12 Elm St.csv');

    expect(downloadedCsvRows($response->streamedContent()))->toHaveCount(4);

    $this->getJson(route('api.v1.accounts.payments.download', [$owner, 'format' => 'csv']))->assertNotFound();
});

test('a ledger download needs a file type the portal makes', function () {
    Sanctum::actingAs($this->tenant);

    $this->getJson(route('api.v1.accounts.payments.download', [$this->account, 'format' => 'xlsx']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['format' => 'The selected format is invalid.']);
});

test('another client cannot download the tenant\'s ledger', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson(route('api.v1.accounts.payments.download', [$this->account, 'format' => 'csv']))->assertNotFound();
});
