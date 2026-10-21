<?php

use App\Jobs\WarmOwnerLedgerBalance;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/** One general ledger transaction, in Propertyware's double-entry shape. */
function apiTransaction(int $id, string $date, string $type, array $entries): array
{
    return ['id' => $id, 'txDate' => $date, 'transactionType' => $type, 'comment' => null, 'glentries' => $entries];
}

function apiEntry(int $accountId, float $credit = 0, float $debit = 0): array
{
    return ['portfolioID' => 2, 'buildingID' => null, 'glaccountID' => $accountId, 'credit' => $credit, 'debit' => $debit, 'comment' => null];
}

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response([
            'id' => '500',
            'type' => 'OWNER',
            'attachedLeases' => [],
            'attachedPortfolios' => [['id' => 2, 'name' => 'Elm Portfolio']],
        ]),
        '*/buildings*' => Http::response([
            // One Propertyware holds a name for, one it does not.
            ['id' => 7, 'portfolioID' => 2, 'name' => 'Elm House', 'address' => ['address' => '12 Elm St', 'city' => 'Austin', 'stateRegion' => 'TX']],
            ['id' => 8, 'portfolioID' => 2, 'address' => ['address' => '99 Oak Ave', 'city' => 'Austin', 'stateRegion' => 'TX']],
        ]),
        '*/units*' => Http::response([]),
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 10, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => 20, 'name' => 'Plumbing', 'accountType' => 'Expense'],
            ['id' => 30, 'name' => 'Management Fees', 'accountType' => 'Expense'],
            ['id' => 50, 'name' => 'Operating Bank', 'accountType' => 'Bank'],
        ]),
        '*/accounting/generalledger*' => Http::response([
            apiTransaction(1, '2026-09-04', 'Tenant Payment', [apiEntry(10, credit: 1771.00), apiEntry(50, debit: 1771.00)]),
            apiTransaction(2, '2026-09-08', 'Bill Payment', [apiEntry(30, debit: 270.00), apiEntry(50, credit: 270.00)]),
            apiTransaction(3, '2026-09-10', 'Bill Payment', [apiEntry(20, debit: 475.00), apiEntry(50, credit: 475.00)]),
        ]),
        '*/leases/payments*' => Http::response([['id' => 1, 'depositDate' => '2026-09-09']]),
        '*/portfolios/*' => Http::response(['id' => 2, 'createdDateTime' => '2026-08-01T00:00:00Z', 'targetOperatingReserve' => 300.0]),
    ]);

    $this->owner = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create(['propertyware_id' => '500']);
});

test('answers what the portfolio earned, spent and has left', function () {
    $this->travelTo('2026-09-23');
    Sanctum::actingAs($this->owner);

    $response = $this->getJson(route('api.v1.accounts.ledger', $this->account))->assertOk();

    expect($response->json('data.income'))->toEqual(1771.00)
        // The management fee is an expense and is also called out on its own.
        ->and($response->json('data.expense'))->toEqual(745.00)
        ->and($response->json('data.managementFee'))->toEqual(270.00)
        ->and($response->json('data.net'))->toEqual(1026.00);

    // The portfolio it answered for, and the ones it could have.
    expect($response->json('meta.portfolio.name'))->toBe('Elm Portfolio')
        ->and($response->json('meta.portfolios'))->toHaveCount(1);

    // The movement itself, newest first, each with the balance it left behind.
    expect($response->json('data.entries'))->toHaveCount(3)
        ->and($response->json('data.entries.0.description'))->toBe('Bill Payment')
        ->and($response->json('data.entries.2.description'))->toBe('Tenant Payment')
        ->and($response->json('data.entries.2.amount'))->toEqual(1771.00);

    // Income against expense, oldest month first, for the chart.
    expect($response->json('data.months'))->toHaveCount(12)
        ->and($response->json('data.months.11.month'))->toBe('2026-09')
        ->and($response->json('data.months.11.income'))->toEqual(1771.00)
        ->and($response->json('data.months.11.expense'))->toEqual(745.00);

    Queue::assertPushed(WarmOwnerLedgerBalance::class);
});

test('refuses a portfolio the owner does not hold', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.ledger', [$this->account, 'portfolio' => 999]))->assertNotFound();
});

test('refuses the ledger to a resident', function () {
    $resident = User::factory()->create();
    $account = ClientAccount::factory()->for($resident)->create(['propertyware_id' => '500']);

    Sanctum::actingAs($resident);

    $this->getJson(route('api.v1.accounts.ledger', $account))->assertNotFound();
});

test('offers the portfolio\'s properties, named the way the owner knows them', function () {
    $this->travelTo('2026-09-23');
    Sanctum::actingAs($this->owner);

    $response = $this->getJson(route('api.v1.accounts.ledger', $this->account))->assertOk();

    // The building's name when there is one, its address when there is not.
    expect(collect($response->json('meta.properties'))->pluck('name')->all())
        ->toBe(['99 Oak Ave', 'Elm House'])
        ->and(collect($response->json('meta.properties'))->pluck('id')->all())
        ->toEqualCanonicalizing([7, 8]);
});
