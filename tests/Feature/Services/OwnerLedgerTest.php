<?php

use App\Enums\ClientRole;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\OwnerLedger;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'services.propertyware.client_id' => 'id',
        'services.propertyware.client_secret' => 'secret',
        'services.propertyware.system_id' => 'system',
        'services.propertyware.base_url' => 'https://pw.test/v1',
    ]);

    $this->scope = new ClientScope(ClientRole::Owner, '500', [], [7]);
});

/**
 * One general ledger transaction, in Propertyware's double-entry shape.
 *
 * @param  array<int, array<string, mixed>>  $entries
 */
function transaction(int $id, string $date, string $type, array $entries): array
{
    return [
        'id' => $id,
        'txDate' => $date,
        'transactionType' => $type,
        'comment' => null,
        'glentries' => $entries,
    ];
}

function glEntry(int $portfolioId, int $accountId, float $credit = 0, float $debit = 0, ?string $comment = null): array
{
    return [
        'portfolioID' => $portfolioId,
        'buildingID' => null,
        'glaccountID' => $accountId,
        'credit' => $credit,
        'debit' => $debit,
        'comment' => $comment,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $transactions
 * @param  array<int, array<string, mixed>>  $payments  Propertyware's tenant payment records.
 */
function fakeLedger(array $transactions, array $payments = []): void
{
    Http::fake([
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 10, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => 20, 'name' => 'Plumbing', 'accountType' => 'Expense'],
            ['id' => 30, 'name' => 'Management Fees', 'accountType' => 'Expense'],
            ['id' => 40, 'name' => 'Owner Draw', 'accountType' => 'Equity'],
            ['id' => 50, 'name' => 'Operating Bank', 'accountType' => 'Bank'],
            ['id' => 60, 'name' => 'Management Income', 'accountType' => 'Income'],
            ['id' => 61, 'name' => 'Tenant Benefit Package', 'accountType' => 'Income', 'parentRef' => 'Management Income'],
        ]),
        '*/accounting/generalledger*' => Http::response($transactions),
        '*/leases/payments*' => Http::response($payments),
        // A recent start date keeps the balance walk short; without one the
        // service falls back to years of history and gives up as cold.
        '*/portfolios/*' => Http::response([
            'id' => 7,
            'createdDateTime' => '2026-08-01T00:00:00Z',
            'targetOperatingReserve' => 300.0,
        ]),
    ]);
}

test('reads income, expenses and the management fee the way Propertyware does', function () {
    fakeLedger([
        transaction(1, '2026-09-04', 'Tenant Payment', [
            glEntry(7, 10, credit: 1771.00),
            // The other side is the company's bank account, not the owner's money.
            glEntry(7, 50, debit: 1771.00),
        ]),
        transaction(2, '2026-09-08', 'Bill Payment', [
            glEntry(7, 30, debit: 270.00),
            glEntry(7, 50, credit: 270.00),
        ]),
        transaction(3, '2026-09-10', 'Bill Payment', [
            glEntry(7, 20, debit: 475.00),
            glEntry(7, 50, credit: 475.00),
        ]),
    ]);

    $ledger = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30');

    expect($ledger['income'])->toBe(1771.00)
        ->and($ledger['expense'])->toBe(745.00)
        ->and($ledger['managementFee'])->toBe(270.00)
        ->and($ledger['net'])->toBe(1026.00);
});

test('an owner draw reduces the balance without counting as an expense', function () {
    fakeLedger([
        transaction(1, '2026-09-01', 'Tenant Payment', [glEntry(7, 10, credit: 9550.00)]),
        transaction(2, '2026-09-15', 'Tenant Refund/Owner Draw', [glEntry(7, 40, debit: 7264.22)]),
    ]);

    $ledger = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30');

    expect($ledger['income'])->toBe(9550.00)
        ->and($ledger['expense'])->toBe(0.0)
        ->and($ledger['net'])->toBe(2285.78);
});

test('says what each line was by where its money went, not by its name', function () {
    fakeLedger([
        transaction(1, '2026-09-01', 'Tenant Payment', [glEntry(7, 10, credit: 1400.00)]),
        transaction(2, '2026-09-08', 'Bill Payment', [glEntry(7, 20, debit: 475.00)]),
        // Propertyware files these two under one type; the accounts tell them apart.
        transaction(3, '2026-09-15', 'Tenant Refund/Owner Draw', [glEntry(7, 40, debit: 500.00)]),
        transaction(4, '2026-09-16', 'Tenant Refund/Owner Draw', [glEntry(7, 10, debit: 100.00)]),
        transaction(5, '2026-09-20', 'Credit Memo', [glEntry(7, 20, credit: 50.00)]),
        transaction(6, '2026-09-22', 'Journal Entry', [glEntry(7, 40, credit: 1000.00)]),
    ]);

    $entries = collect(app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['entries']);

    expect($entries->pluck('kind', 'id')->sortKeys()->all())->toBe([
        1 => 'income',
        2 => 'expense',
        3 => 'draw',
        4 => 'refund',
        5 => 'credit',
        6 => 'contribution',
    ]);
});

test('refuses a portfolio the client does not own', function () {
    fakeLedger([]);

    // The general ledger holds every portfolio in the account, including
    // TexasRenters.com's own books, so asking for one must never be enough.
    app(OwnerLedger::class)->forPortfolio($this->scope, 999, '2026-09-01', '2026-09-30');
})->throws(PropertywareException::class, 'does not belong to this client');

test('ignores the parts of a transaction belonging to another portfolio', function () {
    fakeLedger([
        transaction(1, '2026-09-04', 'Tenant Payment', [
            glEntry(7, 10, credit: 100.00),
            // Same transaction, a different owner's portfolio.
            glEntry(8, 10, credit: 9999.00),
        ]),
    ]);

    $ledger = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30');

    expect($ledger['income'])->toBe(100.00)
        ->and($ledger['entries'])->toHaveCount(1);
});

test('runs a balance forward and lists the newest movement first', function () {
    fakeLedger([
        transaction(1, '2026-09-01', 'Tenant Payment', [glEntry(7, 10, credit: 1000.00)]),
        transaction(2, '2026-09-10', 'Bill Payment', [glEntry(7, 20, debit: 400.00)]),
        transaction(3, '2026-09-20', 'Tenant Payment', [glEntry(7, 10, credit: 250.00)]),
    ]);

    $entries = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['entries'];

    expect(array_column($entries, 'date'))->toBe(['2026-09-20', '2026-09-10', '2026-09-01'])
        ->and(array_column($entries, 'balance'))->toBe([850.00, 600.00, 1000.00]);
});

test('leaves out a transaction that moves none of the owners money', function () {
    fakeLedger([
        // Money moving between the company's own accounts.
        transaction(1, '2026-09-04', 'Bank Deposit', [glEntry(7, 50, credit: 500.00), glEntry(7, 50, debit: 500.00)]),
    ]);

    expect(app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['entries'])->toBeEmpty();
});

test('does not repeat the account name when the comment only echoes it', function () {
    fakeLedger([
        transaction(1, '2026-09-15', 'Tenant Refund/Owner Draw', [
            glEntry(7, 40, debit: 500.00, comment: 'Owner Draw'),
        ]),
    ]);

    $entries = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['entries'];

    expect($entries[0]['details'])->toBe('Owner Draw');
});

test('keeps a comment that says something the account name does not', function () {
    fakeLedger([
        transaction(1, '2026-09-16', 'Bill Payment', [
            glEntry(7, 20, debit: 756.65, comment: '19207 Presa Canyon Dr'),
        ]),
    ]);

    $entries = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['entries'];

    expect($entries[0]['details'])->toBe('Plumbing — 19207 Presa Canyon Dr');
});

test('narrows the ledger to one property when asked', function () {
    fakeLedger([
        transaction(1, '2026-09-04', 'Tenant Payment', [
            [...glEntry(7, 10, credit: 1000.00), 'buildingID' => 100],
        ]),
        transaction(2, '2026-09-05', 'Tenant Payment', [
            [...glEntry(7, 10, credit: 250.00), 'buildingID' => 200],
        ]),
    ]);

    $ledger = app(OwnerLedger::class)->forPortfolio(
        $this->scope, 7, '2026-09-01', '2026-09-30',
        buildingIds: [100],
        locations: [100 => '19207 Presa Canyon Dr'],
    );

    expect($ledger['income'])->toBe(1000.00)
        ->and($ledger['entries'])->toHaveCount(1)
        ->and($ledger['entries'][0]['location'])->toBe('19207 Presa Canyon Dr');
});

test('carries the portfolio balance into a single property view, as Propertyware does', function () {
    fakeLedger([
        transaction(1, '2026-09-04', 'Tenant Payment', [
            [...glEntry(7, 10, credit: 1000.00), 'buildingID' => 100],
        ]),
    ]);

    // Propertyware shows one property's transactions moving the portfolio's
    // own balance, rather than restarting the running total at zero.
    $ledger = app(OwnerLedger::class)->forPortfolio(
        $this->scope, 7, '2026-09-01', '2026-09-30',
        withOpeningBalance: true,
        buildingIds: [100],
    );

    expect($ledger['fromInception'])->toBeTrue();
});

test('keeps the whole management fee when one property is filtered', function () {
    fakeLedger([
        transaction(1, '2026-09-08', 'Bill Payment', [
            [...glEntry(7, 30, debit: 270.00), 'buildingID' => 100],
        ]),
        transaction(2, '2026-09-08', 'Bill Payment', [
            [...glEntry(7, 30, debit: 685.00), 'buildingID' => 200],
        ]),
    ]);

    // The fee is charged to the portfolio, not to a property, so narrowing to
    // one property must not shrink it.
    $ledger = app(OwnerLedger::class)->forPortfolio(
        $this->scope, 7, '2026-09-01', '2026-09-30',
        buildingIds: [100],
    );

    expect($ledger['managementFee'])->toBe(955.00)
        ->and($ledger['expense'])->toBe(270.00);
});

test('groups income and expense by account for the dashboard charts', function () {
    fakeLedger([
        transaction(1, '2026-09-01', 'Tenant Payment', [glEntry(7, 10, credit: 9550.00)]),
        transaction(2, '2026-09-08', 'Bill Payment', [glEntry(7, 30, debit: 955.00)]),
        transaction(3, '2026-09-10', 'Bill Payment', [glEntry(7, 20, debit: 475.00)]),
    ]);

    $ledger = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30');

    expect($ledger['incomeAccounts'])->toBe([['name' => 'Rent', 'amount' => 9550.00]])
        // Largest first, so the chart's colours follow size rather than order.
        ->and($ledger['expenseAccounts'])->toBe([
            ['name' => 'Management Fees', 'amount' => 955.00],
            ['name' => 'Plumbing', 'amount' => 475.00],
        ]);
});

test('charts the biggest five accounts and adds the rest together', function () {
    // Seven expense accounts: a chart of seven slices says nothing, so the
    // two smallest are folded into one.
    Http::fake([
        '*/accounting/glaccounts*' => Http::response(
            collect(range(1, 7))
                ->map(fn (int $n): array => ['id' => 100 + $n, 'name' => "Account {$n}", 'accountType' => 'Expense'])
                ->all(),
        ),
        '*/leases/payments*' => Http::response([]),
        '*/accounting/generalledger*' => Http::response(
            collect(range(1, 7))
                ->map(fn (int $n): array => transaction($n, '2026-09-05', 'Bill Payment', [
                    glEntry(7, 100 + $n, debit: $n * 100.0),
                ]))
                ->all(),
        ),
        '*/portfolios/*' => Http::response(['id' => 7, 'createdDateTime' => '2026-08-01T00:00:00Z']),
    ]);

    $accounts = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['expenseAccounts'];

    expect($accounts)->toHaveCount(6)
        ->and(array_column($accounts, 'name'))->toBe([
            'Account 7', 'Account 6', 'Account 5', 'Account 4', 'Account 3', 'Everything else',
        ])
        // 200 + 100, the two that did not make the chart.
        ->and($accounts[5]['amount'])->toBe(300.0);
});

test('leaves out our own fees that only pass through the owner\'s books', function () {
    fakeLedger([
        transaction(1, '2026-09-01', 'Tenant Payment', [
            glEntry(7, 10, credit: 1500.00),
            glEntry(7, 50, debit: 1500.00),
            glEntry(7, 61, credit: 40.00),
            glEntry(7, 50, debit: 40.00),
        ]),
        transaction(2, '2026-09-03', 'Bill Payment', [
            glEntry(7, 61, debit: 40.00),
            glEntry(7, 50, credit: 40.00),
        ]),
    ]);

    $ledger = app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30');

    expect($ledger['income'])->toBe(1500.00)
        ->and($ledger['endingBalance'])->toBe(1500.00)
        ->and($ledger['entries'])->toHaveCount(1)
        ->and($ledger['entries'][0])->toMatchArray(['amount' => 1500.00, 'details' => 'Rent'])
        ->and(collect($ledger['incomeAccounts'])->pluck('name')->all())->toBe(['Rent']);
});

test('says whether each tenant payment has been deposited yet', function () {
    $this->travelTo('2026-09-10 12:00:00');

    fakeLedger([
        transaction(1, '2026-09-01', 'Tenant Payment', [glEntry(7, 10, credit: 1500.00), glEntry(7, 50, debit: 1500.00)]),
        transaction(2, '2026-09-08', 'Tenant Payment', [glEntry(7, 10, credit: 900.00), glEntry(7, 50, debit: 900.00)]),
        transaction(3, '2026-09-09', 'Tenant Payment', [glEntry(7, 10, credit: 800.00), glEntry(7, 50, debit: 800.00)]),
        transaction(4, '2026-09-09', 'Bill Payment', [glEntry(7, 20, debit: 100.00), glEntry(7, 50, credit: 100.00)]),
    ], [
        ['id' => 1, 'depositDate' => '2026-09-06'],
        ['id' => 2, 'depositDate' => '2026-09-13'],
        ['id' => 3, 'depositDate' => null],
    ]);

    $entries = collect(app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['entries'])->keyBy('id');

    expect($entries[1])->toMatchArray(['settled' => true, 'settledOn' => '2026-09-06'])
        ->and($entries[2])->toMatchArray(['settled' => false, 'settledOn' => null])
        ->and($entries[3])->toMatchArray(['settled' => false, 'settledOn' => null])
        ->and($entries[4])->toMatchArray(['settled' => null, 'settledOn' => null]);
});

test('shows no settled status when Propertyware will not list the payments', function () {
    fakeLedger([
        transaction(1, '2026-09-01', 'Tenant Payment', [glEntry(7, 10, credit: 1500.00), glEntry(7, 50, debit: 1500.00)]),
    ]);
    Http::fake(['*/leases/payments*' => Http::response([], 500)]);

    expect(app(OwnerLedger::class)->forPortfolio($this->scope, 7, '2026-09-01', '2026-09-30')['entries'][0]['settled'])->toBeNull();
});
