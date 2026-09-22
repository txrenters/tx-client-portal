<?php

use App\Jobs\ImportOwnerLedgers;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['services.propertyware.discover_accounts' => false]);

    Http::fake([
        // Contact 501 holds more portfolios than the portal reads at once.
        '*/contacts/*' => fn ($request) => Http::response(str_contains($request->url(), '/contacts/501')
            ? [
                'id' => '501',
                'type' => 'OWNER',
                'attachedLeases' => [],
                'attachedPortfolios' => collect(range(1001, 1020))->map(fn (int $id): array => ['id' => $id, 'name' => "Portfolio {$id}"])->all(),
            ]
            : [
                'id' => '500',
                'type' => 'OWNER',
                'attachedLeases' => [],
                'attachedPortfolios' => [['id' => 2, 'name' => 'Elm Portfolio'], ['id' => 3, 'name' => 'Oak Portfolio']],
            ]),
        // A building is read either by id or per portfolio, as Propertyware allows.
        '*/buildings*' => function ($request) {
            $all = collect([
                ['id' => 7, 'portfolioID' => 2, 'address' => ['address' => '12 Elm St']],
                ['id' => 8, 'portfolioID' => 3, 'address' => ['address' => '99 Oak Ave']],
                ...collect(range(1001, 1020))->map(fn (int $id): array => ['id' => $id, 'portfolioID' => $id, 'address' => ['address' => "{$id} Main St"]])->all(),
            ]);

            if (preg_match('#/buildings/(\d+)#', $request->url(), $matches)) {
                return Http::response($all->firstWhere('id', (int) $matches[1]) ?? []);
            }

            return Http::response($all
                ->filter(fn (array $building): bool => blank($request->data()['portfolioID'] ?? null)
                    || (int) $building['portfolioID'] === (int) $request->data()['portfolioID'])
                ->values()
                ->all());
        },
        '*/units*' => Http::response([]),
        // Propertyware's chart of accounts, which says which splits are expenses.
        '*/accounting/glaccounts*' => Http::response([
            ['id' => 4201, 'name' => 'Management Fees', 'accountType' => 'Expense'],
            ['id' => 4210, 'name' => 'Late Fee', 'accountType' => 'Income'],
            ['id' => 4000, 'name' => 'Rent', 'accountType' => 'Income'],
            ['id' => 1000, 'name' => 'Accounts Receivable', 'accountType' => 'A/R'],
        ]),
        '*/vendors/900' => Http::response(['id' => 900, 'name' => 'Acme Plumbing']),
        // Each lease bills a month's rent and receives most of it.
        '*/leases/adjustments*' => fn ($request) => Http::response([
            ['id' => 1, 'leaseID' => (int) ($request->data()['leaseID'] ?? 0), 'date' => '2026-05-01', 'amount' => 1000.0, 'glAccountID' => 4000],
            ['id' => 2, 'leaseID' => (int) ($request->data()['leaseID'] ?? 0), 'date' => '2026-05-03', 'amount' => -900.0, 'glAccountID' => 1000],
        ]),
        // Two leases on one building: the tenancy that ended, and the one that followed.
        '*/leases*' => fn ($request) => Http::response((int) ($request->data()['buildingID'] ?? 0) === 7
            ? [
                ['id' => 71, 'startDate' => '2024-01-01', 'endDate' => '2026-03-31', 'active' => false],
                ['id' => 72, 'startDate' => '2026-04-01', 'endDate' => '2027-03-31', 'active' => true],
                // Ran before the window, so it is left out.
                ['id' => 70, 'startDate' => '2019-01-01', 'endDate' => '2020-01-01', 'active' => false],
            ]
            : [['id' => 81, 'startDate' => '2025-01-01', 'endDate' => '2027-01-01', 'active' => true]]),
        '*/bills*' => fn ($request) => Http::response(match ((int) ($request->data()['portfolioID'] ?? 0)) {
            2 => [[
                'id' => 111, 'vendorID' => 900, 'billDate' => '2026-09-01', 'dueDate' => '2026-09-30', 'comments' => '19319 Hays Spring, Account#ELB 90419-419132400, Billing period 9/04/2025-10/04/2025', 'workOrderID' => '42',
                // A bill split across this owner's portfolio and someone else's.
                'billNumber' => 67035, 'paymentDate' => '2026-09-05',
                'billSplits' => [
                    ['portfolioID' => 2, 'buildingID' => 7, 'buildingAbbr' => '12ELMST', 'glAccountID' => 4201, 'amount' => 300.0, 'amountPaid' => 300.0],
                    // Our own fee recovered from the tenant: never the owner's cost.
                    ['portfolioID' => 2, 'buildingID' => 7, 'buildingAbbr' => '12ELMST', 'glAccountID' => 4210, 'amount' => 120.0, 'amountPaid' => 120.0],
                    ['portfolioID' => 99, 'buildingID' => 99, 'glAccountID' => 4201, 'amount' => 700.0, 'amountPaid' => 700.0],
                ],
                'amount' => 1120.0,
            ], [
                'id' => 112, 'vendorID' => 900, 'billDate' => '2019-01-01', 'comments' => 'Too old',
                'billSplits' => [['portfolioID' => 2, 'buildingID' => 7, 'amount' => 50.0, 'amountPaid' => 50.0]],
                'amount' => 50.0,
            ]],
            3 => [[
                'id' => 113, 'vendorID' => 900, 'billDate' => '2026-08-15', 'comments' => 'Roof patch',
                'billSplits' => [['portfolioID' => 3, 'buildingID' => 8, 'amount' => 200.0, 'amountPaid' => 0.0]],
                'amount' => 200.0,
            ]],
            default => [],
        }),
    ]);

    $this->owner = User::factory()->create();
    $this->account = ClientAccount::factory()->owner()->for($this->owner)->create(['propertyware_id' => '500']);
});

test('shows the owner only their share of each bill, by portfolio', function () {
    $this->actingAs($this->owner);

    // The page defaults to this month; these fixtures span a couple of years.
    $response = partialGet(route('owner.financials', ['from' => '2024-01-01', 'to' => '2026-12-31']), 'owner/Financials', 'financials')->assertOk();

    // 300 of the shared bill, not its 1,000 total, plus 200 on the other portfolio.
    expect($response->json('props.financials.totals'))->toMatchArray([
        'expenses' => 500.0,
        'paid' => 300.0,
        'outstanding' => 200.0,
        'bills' => 2,
    ])
        ->and($response->json('props.financials.portfolios'))->toMatchArray([
            // Each portfolio carries the same three figures as the page totals,
            // so one can be shown on its own without asking for it again.
            ['id' => 2, 'name' => 'Elm Portfolio', 'expenses' => 300.0, 'paid' => 300.0, 'outstanding' => 0.0, 'bills' => 1],
            ['id' => 3, 'name' => 'Oak Portfolio', 'expenses' => 200.0, 'paid' => 0.0, 'outstanding' => 200.0, 'bills' => 1],
        ])
        ->and($response->json('props.financials.bills.0'))->toMatchArray([
            'vendor' => 'Acme Plumbing',
            'number' => '67035',
            // The 120 posted to an income account is our fee, not their expense.
            'amount' => 300.0,
            'accounts' => ['Management Fees'],
            'status' => 'paid',
            // The building as the owner portal names it, not its abbreviation.
            'buildings' => ['12 Elm St'],
            'workOrderId' => '42',
            'portfolios' => ['Elm Portfolio'],
        ]);
});

test('serves a large owner from what is held while the rest imports', function () {
    Queue::fake();
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '501']);
    $this->actingAs($user);

    $response = partialGet(route('owner.financials'), 'owner/Financials', 'financials')->assertOk();

    // Twenty portfolios: nothing is read on the request, the import fetches them.
    expect($response->json('props.financials.importing'))->toBeTrue()
        ->and($response->json('props.financials.bills'))->toBe([]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/bills'));
    Queue::assertPushed(ImportOwnerLedgers::class);
});

test('narrows the bills to one portfolio, or adds several together', function () {
    $this->actingAs($this->owner);

    $one = partialGet(route('owner.financials', ['portfolios' => [3], ...['from' => '2024-01-01', 'to' => '2026-12-31']]), 'owner/Financials', 'financials')->assertOk();

    expect($one->json('props.financials.totals.expenses'))->toEqual(200.0)
        ->and($one->json('props.financials.portfolios'))->toHaveCount(1);

    // Ticking both adds their bills up.
    $both = partialGet(route('owner.financials', ['portfolios' => [2, 3], ...['from' => '2024-01-01', 'to' => '2026-12-31']]), 'owner/Financials', 'financials')->assertOk();

    expect($both->json('props.financials.totals.expenses'))->toEqual(500.0)
        ->and($both->json('props.financials.portfolios'))->toHaveCount(2);
});

test('counts revenue across the portfolios that are ticked', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.financials', ['portfolios' => [3], ...['from' => '2024-01-01', 'to' => '2026-12-31']]), 'owner/Financials', 'revenue')->assertOk();

    expect($response->json('props.revenue.properties'))->toHaveCount(1)
        ->and($response->json('props.revenue.properties.0.id'))->toBe(8);
});

test('the app reads the same financials, and tenants have none', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson(route('api.v1.accounts.financials', [$this->account, 'from' => '2024-01-01', 'to' => '2026-12-31']))
        ->assertOk()
        ->assertJsonPath('meta.totals.expenses', 500)
        ->assertJsonPath('data.0.vendor', 'Acme Plumbing');

    $resident = ClientAccount::factory()->resident()->for($this->owner)->create(['propertyware_id' => '900']);

    $this->getJson(route('api.v1.accounts.financials', $resident))->assertNotFound();
});

test('totals what each property billed and received, across every lease in the period', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.financials', ['from' => '2024-01-01', 'to' => '2026-12-31']), 'owner/Financials', 'revenue')->assertOk();

    $properties = collect($response->json('props.revenue.properties'));

    // 12 Elm St ran two leases in the window, 99 Oak Ave one.
    // Each row says which portfolio it belongs to, so a portfolio can add its own up.
    expect($properties->firstWhere('id', 7))->toMatchArray(['billed' => 2000.0, 'received' => 1800.0, 'leases' => 2, 'portfolioId' => 2])
        ->and($properties->firstWhere('id', 8))->toMatchArray(['billed' => 1000.0, 'received' => 900.0, 'leases' => 1])
        ->and($response->json('props.revenue.totals'))->toMatchArray(['billed' => 3000.0, 'received' => 2700.0, 'properties' => 2]);
});

test('hands the page numeric ids, so picking a portfolio still lists its properties', function () {
    $this->actingAs($this->owner);

    // Query strings arrive as strings and the "integer" rule validates without
    // casting. The page matches ids with ===, so a string id silently emptied
    // the properties menu the moment a portfolio was picked.
    $this->get(route('owner.financials', ['portfolios' => [2], 'properties' => [7]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.portfolios', [2])
            ->where('filters.properties', [7]));
})->group('regression');

test('keeps utility account numbers out of a bills note', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.financials'), 'owner/Financials', 'financials')->assertOk();

    expect($response->json('props.financials.bills.0.description'))->toBe('19319 Hays Spring, Billing period 9/04/2025-10/04/2025');
});

test('names the property beside each portfolio in the picker', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.financials'), 'owner/Financials', 'portfolios')->assertOk();

    expect($response->json('props.portfolios'))->toMatchArray([
        ['id' => 2, 'name' => 'Elm Portfolio', 'properties' => ['12 Elm St']],
        ['id' => 3, 'name' => 'Oak Portfolio', 'properties' => ['99 Oak Ave']],
    ]);
});

test('names every portfolio with its property, however many they hold', function () {
    $user = User::factory()->create();
    ClientAccount::factory()->owner()->for($user)->create(['propertyware_id' => '501']);
    $this->actingAs($user);

    $response = partialGet(route('owner.financials'), 'owner/Financials', 'portfolios')->assertOk();

    // Every portfolio is offered, named, and carries its property.
    expect($response->json('props.portfolios'))->toHaveCount(20)
        ->and($response->json('props.portfolios.0.properties'))->toBe(['1001 Main St']);
});

test('starts importing the owners leases as soon as the page opens', function () {
    Queue::fake();
    $this->actingAs($this->owner);

    $this->get(route('owner.financials'))->assertOk();

    Queue::assertPushed(ImportOwnerLedgers::class, fn (ImportOwnerLedgers $job): bool => $job->scope->contactId === '500');
});

test('says so while the import is still running', function () {
    // The job is queued, not run here, so its "running" mark stands.
    Queue::fake();
    Cache::put(ImportOwnerLedgers::cacheKey('500'), true, now()->addMinutes(5));
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.financials'), 'owner/Financials', 'revenue')->assertOk();

    expect($response->json('props.revenue.importing'))->toBeTrue();
});

test('narrows the bills and revenue to a chosen period', function () {
    $this->actingAs($this->owner);

    // The September bill is inside the window; the August one is not.
    $response = partialGet(
        route('owner.financials', ['from' => '2026-09-01', 'to' => '2026-09-30']),
        'owner/Financials',
        'financials',
    )->assertOk();

    expect($response->json('props.financials.bills'))->toHaveCount(1)
        ->and($response->json('props.financials.totals.expenses'))->toEqual(300.0)
        ->and($response->json('props.financials.from'))->toBe('2026-09-01')
        ->and($response->json('props.financials.to'))->toBe('2026-09-30');
});

test('narrows to one property inside a portfolio', function () {
    $this->actingAs($this->owner);

    $wide = ['from' => '2024-01-01', 'to' => '2026-12-31'];

    $response = partialGet(
        route('owner.financials', ['properties' => [8], ...$wide]),
        'owner/Financials',
        'revenue',
        'financials',
    )->assertOk();

    // Only 99 Oak Ave: its revenue, and only the bill splits that name it.
    expect($response->json('props.revenue.properties'))->toHaveCount(1)
        ->and($response->json('props.revenue.properties.0.id'))->toBe(8)
        ->and($response->json('props.financials.totals.expenses'))->toEqual(200.0)
        ->and($response->json('props.financials.bills'))->toHaveCount(1);
});

test('offers the owners properties to tick, each with the portfolio it sits in', function () {
    $this->actingAs($this->owner);

    $response = partialGet(route('owner.financials'), 'owner/Financials', 'properties')->assertOk();

    // The portfolio id is what narrows the picker once a portfolio is ticked.
    expect($response->json('props.properties'))->toMatchArray([
        ['id' => 7, 'name' => '12 Elm St', 'portfolioId' => 2],
        ['id' => 8, 'name' => '99 Oak Ave', 'portfolioId' => 3],
    ]);
});

test('dates a bill by the day it was paid, as the owner statement does', function () {
    $this->actingAs($this->owner);

    // Billed 1 September, paid on the 5th: the window starts after the bill date.
    $response = partialGet(route('owner.financials', ['from' => '2026-09-02', 'to' => '2026-09-30']), 'owner/Financials', 'financials')->assertOk();

    expect($response->json('props.financials.bills'))->toHaveCount(1)
        ->and($response->json('props.financials.bills.0'))->toMatchArray([
            'number' => '67035',
            'date' => '2026-09-05',
            'billDate' => '2026-09-01',
            'paymentDate' => '2026-09-05',
        ]);
});

test('shows only the bills still outstanding, or only those paid', function (string $status, array $amounts) {
    $this->actingAs($this->owner);

    $response = partialGet(
        route('owner.financials', ['status' => $status, 'from' => '2024-01-01', 'to' => '2026-12-31']),
        'owner/Financials',
        'financials',
    )->assertOk();

    expect(collect($response->json('props.financials.bills'))->pluck('amount')->all())->toEqual($amounts);
})->with([
    'outstanding' => ['unpaid', [200.0]],
    'paid' => ['paid', [300.0]],
]);
