<?php

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

/** One general ledger transaction on portfolio 2, in Propertyware's double-entry shape. */
function statementTransaction(int $id, string $date, string $type, array $entries): array
{
    return ['id' => $id, 'txDate' => $date, 'transactionType' => $type, 'comment' => null, 'glentries' => array_map(
        fn (array $entry): array => ['portfolioID' => 2, 'buildingID' => 7, 'comment' => null, 'credit' => 0, 'debit' => 0, ...$entry],
        $entries,
    )];
}

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    $this->travelTo('2026-10-15 12:00:00');
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        '*/contacts/*' => Http::response(['id' => '500', 'type' => 'OWNER', 'attachedLeases' => [], 'attachedPortfolios' => [['id' => 2, 'name' => 'Elm Portfolio']]]),
        '*/buildings*' => Http::response([['id' => 7, 'portfolioID' => 2, 'name' => 'Elm House', 'address' => ['address' => '12 Elm St']]]),
        '*/units*' => Http::response([]),
        '*/portfolios*' => Http::response(['id' => 2, 'name' => 'Elm Portfolio', 'createdDateTime' => '2026-09-01T00:00:00Z']),
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 10, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => 11, 'name' => 'Late Fee', 'accountType' => 'Income'],
            ['id' => 20, 'name' => 'Plumbing', 'accountType' => 'Expense'],
            ['id' => 40, 'name' => 'Owner Draw', 'accountType' => 'Equity'],
            ['id' => 50, 'name' => 'Operating Bank', 'accountType' => 'Bank'],
        ]),
        '*/accounting/generalledger*' => Http::response([
            statementTransaction(1, '2026-09-02', 'Tenant Payment', [['glaccountID' => 10, 'credit' => 1500], ['glaccountID' => 11, 'credit' => 50], ['glaccountID' => 50, 'debit' => 1550]]),
            statementTransaction(2, '2026-09-10', 'Bill Payment', [['glaccountID' => 20, 'debit' => 200], ['glaccountID' => 50, 'credit' => 200]]),
            statementTransaction(3, '2026-09-20', 'Owner Draw', [['glaccountID' => 40, 'debit' => 1000], ['glaccountID' => 50, 'credit' => 1000]]),
        ]),
        '*/leases/payments*' => Http::response([['id' => 1, 'depositDate' => '2026-09-05']]),
    ]);

    $this->user = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->user)->create(['propertyware_id' => '500']);
});

test('gives the app last month\'s statement, account by account', function () {
    Sanctum::actingAs($this->user);

    $response = $this->getJson(route('api.v1.accounts.statement', $this->account))->assertOk();

    expect($response->json('data'))->toMatchArray([
        'portfolio' => ['id' => 2, 'name' => 'Elm Portfolio'],
        'month' => '2026-09',
        'label' => 'September 2026',
        'income' => 1550,
        'expense' => 200,
        'draws' => 1000,
        'incomeAccounts' => [['name' => 'Rent', 'amount' => 1500], ['name' => 'Late Fee', 'amount' => 50]],
        'expenseAccounts' => [['name' => 'Plumbing', 'amount' => 200]],
    ])
        ->and(collect($response->json('data.entries'))->pluck('balance')->all())->toBe([1550, 1350, 350])
        ->and($response->json('data.entries.0.settled'))->toBeTrue()
        ->and($response->json('meta.months.0'))->toBe(['value' => '2026-09', 'label' => 'September 2026'])
        ->and($response->json('meta.months'))->toHaveCount(24);
});

test('falls back to last month for a month that is not offered', function (string $month) {
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.v1.accounts.statement', [$this->account, 'month' => $month]))
        ->assertOk()
        ->assertJsonPath('data.month', '2026-09');
})->with(['the month in progress' => '2026-10', 'too long ago' => '2020-01']);

test('answers 404 for a portfolio the owner does not hold', function () {
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.v1.accounts.statement', [$this->account, 'portfolio' => 99]))->assertNotFound();
});

test('answers 404 for a resident account', function () {
    $resident = ClientAccount::factory()->resident()->for($this->user)->create(['propertyware_id' => '600']);
    Sanctum::actingAs($this->user);

    $this->getJson(route('api.v1.accounts.statement', $resident))->assertNotFound();
});

test('shows the owner their statement on the web', function () {
    $this->actingAs($this->user);

    partialGet(route('owner.statements', ['month' => '2026-09']), 'owner/Statement', 'statement')
        ->assertOk()
        ->assertJsonPath('props.statement.label', 'September 2026')
        ->assertJsonPath('props.statement.endingBalance', 350);
});
