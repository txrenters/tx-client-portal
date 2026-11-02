<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/** One general ledger transaction on portfolio 2, in Propertyware's double-entry shape. */
function incomeTransaction(int $id, string $date, array $entries): array
{
    return ['id' => $id, 'txDate' => $date, 'postDate' => $date, 'transactionType' => 'Journal', 'comment' => null, 'glentries' => array_map(
        fn (array $entry): array => ['portfolioID' => 2, 'buildingID' => 7, 'comment' => null, 'credit' => 0, 'debit' => 0, ...$entry],
        $entries,
    )];
}

beforeEach(function () {
    Cache::flush();
    $this->travelTo('2026-10-22 12:00:00');
    config(['services.propertyware.discover_accounts' => false]);

    // The Pesek portfolio's year from Propertyware's own report: rent from
    // July, a leasing fee and trip charge that month, the management fee
    // every month, and a pass-through fee that comes in and goes back out.
    $this->ledger = [
        incomeTransaction(1, '2026-07-03', [['glaccountID' => 10, 'credit' => 4200], ['glaccountID' => 50, 'debit' => 4200]]),
        incomeTransaction(2, '2026-07-05', [['glaccountID' => 21, 'debit' => 2600], ['glaccountID' => 22, 'debit' => 69], ['glaccountID' => 20, 'debit' => 89], ['glaccountID' => 50, 'credit' => 2758]]),
        incomeTransaction(3, '2026-07-06', [['glaccountID' => 31, 'credit' => 25], ['glaccountID' => 50, 'debit' => 25]]),
        incomeTransaction(4, '2026-07-07', [['glaccountID' => 31, 'debit' => 25], ['glaccountID' => 50, 'credit' => 25]]),
        // Our own admin charge, filed at the top level rather than under Management Income.
        incomeTransaction(10, '2026-08-04', [['glaccountID' => 33, 'credit' => 15], ['glaccountID' => 50, 'debit' => 15]]),
        incomeTransaction(5, '2026-08-03', [['glaccountID' => 10, 'credit' => 2574.19], ['glaccountID' => 50, 'debit' => 2574.19]]),
        incomeTransaction(6, '2026-08-05', [['glaccountID' => 20, 'debit' => 89], ['glaccountID' => 50, 'credit' => 89]]),
        incomeTransaction(7, '2026-09-03', [['glaccountID' => 10, 'credit' => 4200], ['glaccountID' => 20, 'debit' => 89], ['glaccountID' => 50, 'debit' => 4111]]),
        incomeTransaction(8, '2026-10-03', [['glaccountID' => 10, 'credit' => 4200], ['glaccountID' => 20, 'debit' => 89], ['glaccountID' => 50, 'debit' => 4111]]),
        // Another portfolio's books in the same ledger never count.
        [...incomeTransaction(9, '2026-10-04', [['glaccountID' => 10, 'credit' => 999]]), 'glentries' => [['portfolioID' => 3, 'glaccountID' => 10, 'credit' => 999, 'debit' => 0]]],
    ];

    $this->buildings = [['id' => 7, 'portfolioID' => 2, 'name' => 'Elm House', 'address' => ['address' => '12 Elm St']]];

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Pesek,Douglas']]]),
        '*/buildings*' => fn () => Http::response($this->buildings),
        '*/units*' => Http::response([]),
        '*/portfolios*' => Http::response(['id' => 2, 'name' => 'Pesek,Douglas', 'createdDateTime' => '2026-06-01T00:00:00Z']),
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 10, 'name' => 'Rent', 'accountType' => 'Income', 'accountNumber' => '4000', 'parentRef' => ''],
            ['id' => 30, 'name' => 'Management Income', 'accountType' => 'Income', 'accountNumber' => '4200', 'parentRef' => ''],
            ['id' => 31, 'name' => 'Tenant Benefit Package', 'accountType' => 'Income', 'accountNumber' => '4027', 'parentRef' => 'Management Income'],
            ['id' => 33, 'name' => 'Management Income Admin Charge', 'accountType' => 'Income', 'accountNumber' => '4025', 'parentRef' => ''],
            ['id' => 20, 'name' => 'Management Fees', 'accountType' => 'Expense', 'accountNumber' => '4201', 'parentRef' => ''],
            ['id' => 21, 'name' => 'Leasing Fee', 'accountType' => 'Expense', 'accountNumber' => '5001', 'parentRef' => ''],
            ['id' => 22, 'name' => 'Vendor Trip Charge', 'accountType' => 'Expense', 'accountNumber' => '5625', 'parentRef' => ''],
            ['id' => 50, 'name' => 'Operating Bank', 'accountType' => 'Bank', 'accountNumber' => '1000', 'parentRef' => ''],
        ]),
        '*/accounting/generalledger*' => function (Request $request) {
            $from = $request->data()['postDateStart'];
            $to = $request->data()['postDateEnd'];

            return Http::response(array_values(array_filter($this->ledger, fn (array $tx): bool => $tx['postDate'] >= $from && $tx['postDate'] <= $to)));
        },
    ]);

    $this->user = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->user)->create(['propertyware_id' => '500']);
});

test('adds up the year account by account, month by month, as Propertyware does', function () {
    Sanctum::actingAs($this->user);

    $response = $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'year' => 2026]))->assertOk();
    $data = $response->json('data');

    expect($data['portfolio'])->toBe(['id' => 2, 'name' => 'Pesek,Douglas'])
        ->and($data['basis'])->toBe('cash')
        ->and($data['months'])->toHaveCount(12)
        ->and($data['months'][6])->toBe(['value' => '2026-07', 'label' => 'JUL 26'])
        ->and($data['totalIncome'])->toEqual(15174.19)
        ->and($data['totalExpense'])->toEqual(3025)
        ->and($data['totalNetIncome'])->toEqual(12149.19)
        ->and(array_slice($data['netIncome'], 6, 4))->toEqual([1442, 2485.19, 4111, 4111])
        ->and($response->json('meta.years.0'))->toBe(2026);

    // Our own income never reaches an owner: not the Management Income group,
    // and not the admin charge filed at the top level.
    expect(collect($data['income']['rows'])->map(fn (array $row): array => [$row['kind'], $row['depth'], $row['name'], $row['number'], $row['total']])->all())->toEqual([
        ['account', 0, 'Rent', '4000', 15174.19],
    ]);

    expect(collect($data['expense']['rows'])->map(fn (array $row): array => [$row['name'], $row['number'], $row['amounts'][6]])->all())->toEqual([
        ['Leasing Fee', '5001', 2600],
        ['Management Fees', '4201', 89],
        ['Vendor Trip Charge', '5625', 69],
    ])->and($data['expense']['totals'][6])->toEqual(2758);
});

test('sends the year as a PDF without keeping it', function () {
    Sanctum::actingAs($this->user);

    $response = $this->get(route('api.v1.accounts.income-statement.pdf', [$this->account, 'year' => 2026]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('Income Statement 2026 - Pesek,Douglas.pdf')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
});

test('owners download it from the web portal too', function () {
    $this->actingAs($this->user);

    $response = $this->get(route('owner.statements.income-pdf', ['year' => 2026]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

test('falls back to the latest year for one not on offer', function () {
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'year' => 2001]))
        ->assertOk()
        ->assertJsonPath('data.year', 2026);
});

test('answers 404 for a portfolio the owner does not hold, or a resident', function () {
    Sanctum::actingAs($this->user);
    $resident = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '600']);

    $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'portfolio' => 99]))->assertNotFound();
    $this->get(route('api.v1.accounts.income-statement.pdf', [$this->account, 'portfolio' => 99]))->assertNotFound();
    $this->getJson(route('api.v1.accounts.income-statement', $resident))->assertNotFound();
});

test('runs across two years, up to twelve months', function () {
    Sanctum::actingAs($this->user);

    $response = $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'from' => '2025-11', 'to' => '2026-10']))->assertOk();

    expect($response->json('data.months'))->toHaveCount(12)
        ->and($response->json('data.months.0'))->toBe(['value' => '2025-11', 'label' => 'NOV 25'])
        ->and($response->json('data.months.11'))->toBe(['value' => '2026-10', 'label' => 'OCT 26'])
        ->and($response->json('data.label'))->toBe('Nov 2025 – Oct 2026')
        ->and($response->json('data.from'))->toBe('2025-11-01')
        ->and($response->json('data.to'))->toBe('2026-10-31')
        ->and($response->json('data.totalIncome'))->toEqual(15174.19)
        ->and($response->json('meta.range'))->toBe(['earliest' => '2021-01', 'latest' => '2026-10', 'maxMonths' => 12]);

    $this->get(route('api.v1.accounts.income-statement.pdf', [$this->account, 'from' => '2025-11', 'to' => '2026-10']))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Income Statement Nov 2025 to Oct 2026 - Pesek,Douglas.pdf"');
});

test('a shorter run covers only its own months', function () {
    Sanctum::actingAs($this->user);

    $response = $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'from' => '2026-08', 'to' => '2026-09']))->assertOk();

    expect($response->json('data.months'))->toHaveCount(2)
        ->and($response->json('data.label'))->toBe('Aug 2026 – Sep 2026')
        ->and($response->json('data.totalIncome'))->toEqual(6774.19)
        ->and($response->json('data.totalExpense'))->toEqual(178);
});

test('refuses more than twelve months, months out of order, or months not on offer', function (array $query, string $field) {
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.v1.accounts.income-statement', [$this->account, ...$query]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->getJson(route('api.v1.accounts.income-statement.pdf', [$this->account, ...$query]))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'thirteen months' => [['from' => '2025-10', 'to' => '2026-10'], 'to'],
    'out of order' => [['from' => '2026-05', 'to' => '2026-02'], 'to'],
    'too long ago' => [['from' => '2020-12', 'to' => '2021-03'], 'from'],
    'not yet' => [['from' => '2026-10', 'to' => '2026-11'], 'from'],
]);

test('narrows to one property, leaving out the rest of the portfolio', function () {
    Sanctum::actingAs($this->user);

    // A second property's rent in August, and a bank charge filed against
    // the portfolio as a whole, with no building.
    $this->buildings[] = ['id' => 8, 'portfolioID' => 2, 'name' => '', 'address' => ['address' => '9 Oak Ave']];
    $this->ledger = [
        ['id' => 1, 'txDate' => '2026-08-03', 'postDate' => '2026-08-03', 'transactionType' => 'Journal', 'comment' => null, 'glentries' => [
            ['portfolioID' => 2, 'buildingID' => 7, 'glaccountID' => 10, 'credit' => 1000, 'debit' => 0, 'comment' => null],
            ['portfolioID' => 2, 'buildingID' => 8, 'glaccountID' => 10, 'credit' => 500, 'debit' => 0, 'comment' => null],
            ['portfolioID' => 2, 'buildingID' => null, 'glaccountID' => 22, 'credit' => 0, 'debit' => 30, 'comment' => null],
        ]],
    ];

    $whole = $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'from' => '2026-08', 'to' => '2026-08']))->assertOk();

    expect($whole->json('data.totalIncome'))->toEqual(1500)
        ->and($whole->json('data.totalExpense'))->toEqual(30)
        ->and($whole->json('data.property'))->toBeNull()
        ->and($whole->json('meta.properties'))->toBe([['id' => 8, 'name' => '9 Oak Ave'], ['id' => 7, 'name' => 'Elm House']]);

    $one = $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'from' => '2026-08', 'to' => '2026-08', 'property' => 8]))->assertOk();

    expect($one->json('data.totalIncome'))->toEqual(500)
        ->and($one->json('data.totalExpense'))->toEqual(0)
        ->and($one->json('data.property'))->toBe(['id' => 8, 'name' => '9 Oak Ave']);

    $this->get(route('api.v1.accounts.income-statement.pdf', [$this->account, 'from' => '2026-08', 'to' => '2026-08', 'property' => 8]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Income Statement Aug 2026 - 9 Oak Ave.pdf"');

    // A building that is not the owner's, or not in this portfolio, is not found.
    $this->getJson(route('api.v1.accounts.income-statement', [$this->account, 'property' => 99]))->assertNotFound();
    $this->get(route('api.v1.accounts.income-statement.pdf', [$this->account, 'property' => 99]))->assertNotFound();
});

test('owners download one property\'s statement from the web portal too', function () {
    $this->actingAs($this->user);
    $this->buildings[] = ['id' => 8, 'portfolioID' => 2, 'name' => '', 'address' => ['address' => '9 Oak Ave']];

    $this->get(route('owner.statements.income-pdf', ['year' => 2026, 'property' => 8]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Income Statement 2026 - 9 Oak Ave.pdf"');

    $this->get(route('owner.statements.income-pdf', ['year' => 2026, 'property' => 99]))->assertNotFound();
});
