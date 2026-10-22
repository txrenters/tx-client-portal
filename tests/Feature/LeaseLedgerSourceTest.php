<?php

use App\Services\Propertyware\LeaseLedgerSource;
use App\Services\Propertyware\PropertywareClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.propertyware.client_id' => 'id',
        'services.propertyware.client_secret' => 'secret',
        'services.propertyware.base_url' => 'https://pw.test/v1',
    ]);

    Http::fake([
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 10, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => 20, 'name' => 'Accounts Receivable', 'accountType' => 'A/R'],
            ['id' => 30, 'name' => 'Late Fee', 'accountType' => 'Income'],
            ['id' => 40, 'name' => 'Prepayments', 'accountType' => 'Current Liability'],
        ]),
        // Propertyware signs the ledger from the tenant's account: a charge is
        // positive, money coming in is negative.
        '*/leases/adjustments*' => Http::response([
            ['id' => 1, 'leaseID' => 55, 'date' => '2026-09-01', 'amount' => 2400.0, 'glAccountID' => 10, 'refNo' => 'INV-1', 'comments' => 'September rent'],
            ['id' => 2, 'leaseID' => 55, 'date' => '2026-09-03', 'amount' => -2400.0, 'glAccountID' => 20, 'refNo' => 'EP-9'],
            // Posted against receivables as a debit: money going back out.
            ['id' => 3, 'leaseID' => 55, 'date' => '2026-09-07', 'amount' => 2400.0, 'glAccountID' => 20],
            ['id' => 4, 'leaseID' => 55, 'date' => '2026-09-10', 'amount' => -50.0, 'glAccountID' => 30],
            ['id' => 5, 'leaseID' => 55, 'date' => '2026-09-15', 'amount' => -40.0, 'glAccountID' => 40, 'comments' => 'PREPAY Applied to Charges'],
            // Neither of these is a ledger line: no money, and no date.
            ['id' => 8, 'leaseID' => 55, 'date' => '2026-09-01', 'amount' => 0.0, 'glAccountID' => 10],
            ['id' => 9, 'leaseID' => 55, 'date' => '', 'amount' => 100.0, 'glAccountID' => 10],
        ]),
        // Read only for when each payment was deposited.
        '*/leases/payments*' => fn () => $this->payments,
    ]);
    $this->payments = Http::response([]);
});

function ledgerSource(): LeaseLedgerSource
{
    return new LeaseLedgerSource(app(PropertywareClient::class));
}

test('reads the consolidated ledger and names each line', function () {
    $entries = collect(ledgerSource()->entries(55));

    expect($entries)->toHaveCount(5)
        ->and($entries->pluck('kind', 'external_id')->all())->toBe([
            '1' => 'charge',
            '2' => 'payment',
            '3' => 'return',
            '4' => 'credit',
            '5' => 'prepayment',
        ]);
});

test('takes its lines from the consolidated ledger only, never the endpoints that repeat it', function () {
    $this->payments = Http::response([['id' => 99, 'leaseID' => 55, 'date' => '2026-09-20', 'amount' => -500.0, 'depositDate' => '2026-09-22']]);

    $ids = collect(ledgerSource()->entries(55))->pluck('external_id');

    // /leases/charges and /leases/payments hold the same money broken down, so
    // taking lines from them as well would count every line twice.
    expect($ids)->not->toContain('99');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/leases/charges'));
});

test('marks when each payment was deposited, matched to its ledger line by id', function () {
    $this->payments = Http::response([
        ['id' => 2, 'leaseID' => 55, 'depositDate' => '2026-09-05T00:00:00'],
    ]);

    $entries = collect(ledgerSource()->entries(55))->keyBy('external_id');

    expect($entries['2'])->toMatchArray(['deposit_checked' => true, 'deposited_on' => '2026-09-05'])
        // Only payments carry a deposit.
        ->and($entries['1'])->toMatchArray(['deposit_checked' => false, 'deposited_on' => null])
        ->and($entries['3'])->toMatchArray(['deposit_checked' => false, 'deposited_on' => null])
        ->and($entries['5'])->toMatchArray(['deposit_checked' => false, 'deposited_on' => null]);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/leases/payments') && $request['leaseID'] == 55);
});

test('leaves payments unchecked when their records cannot be read', function () {
    $this->payments = Http::response([], 500);

    $entries = collect(ledgerSource()->entries(55))->keyBy('external_id');

    expect($entries)->toHaveCount(5)
        ->and($entries['2'])->toMatchArray(['deposit_checked' => false, 'deposited_on' => null]);
});

test('names a line from the chart of accounts', function () {
    $entries = collect(ledgerSource()->entries(55))->keyBy('external_id');

    expect($entries['1']['description'])->toBe('Rent')
        ->and($entries['1']['detail'])->toBe('September rent')
        ->and($entries['1']['reference'])->toBe('INV-1')
        ->and($entries['2']['description'])->toBe('Payment')
        ->and($entries['3']['description'])->toBe('Payment returned')
        ->and($entries['4']['description'])->toBe('Late Fee credit')
        ->and($entries['5']['description'])->toBe('Prepayment');
});

test('records every amount as a positive figure with a direction', function () {
    $entries = collect(ledgerSource()->entries(55))->keyBy('external_id');

    // The payment arrives negative; it is stored as a credit of 2400.
    expect($entries['2']['amount'])->toBe(2400.0)
        ->and($entries['2']['direction'])->toBe('credit')
        ->and($entries['1']['direction'])->toBe('debit')
        ->and($entries['3']['direction'])->toBe('debit');
});

test('skips rows with no money or no date', function () {
    $ids = collect(ledgerSource()->entries(55))->pluck('external_id');

    expect($ids)->not->toContain('8', '9');
});
