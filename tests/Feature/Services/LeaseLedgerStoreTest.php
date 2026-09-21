<?php

use App\Jobs\SyncLeaseLedger;
use App\Models\LeaseLedgerEntry;
use App\Models\LeaseLedgerSync;
use App\Services\Propertyware\LeaseLedgerStore;
use App\Services\Propertyware\PropertywareSoap;
use Illuminate\Support\Facades\Queue;

function ledgerItem(int $id, string $date, float $amount, string $name = 'Rent', string $type = 'Income'): array
{
    return [
        'ID' => $id,
        'date' => $date,
        'amount' => $amount,
        'comments' => '',
        'referenceNumber' => '',
        'account' => ['name' => $name, 'accountCode' => $name === 'Rent' ? 'RENT' : 'AR', 'accountType' => $type],
    ];
}

test('never keeps a page waiting: the read is queued and served from our copy', function () {
    Queue::fake();
    $store = new LeaseLedgerStore(Mockery::mock(PropertywareSoap::class));

    expect($store->entries(55, '2025-01-01', '2026-12-31'))->toBeEmpty()
        ->and($store->isEmpty(55))->toBeTrue();

    Queue::assertPushed(SyncLeaseLedger::class, fn (SyncLeaseLedger $job): bool => $job->leaseId === 55);
});

test('reads a lease once, then leaves it alone for a day', function () {
    Queue::fake();
    $soap = Mockery::mock(PropertywareSoap::class);
    $soap->shouldReceive('leaseLedger')->once()->andReturn([
        ledgerItem(1, '2026-01-01', 1400.0),
        ledgerItem(2, '2026-01-03', -1400.0, 'Accounts Receivable', 'A/R'),
    ]);

    $store = new LeaseLedgerStore($soap);
    $store->sync(55, '2025-01-01', '2026-12-31');

    expect($store->entries(55, '2025-01-01', '2026-12-31'))->toHaveCount(2)
        ->and($store->isStale(55, '2025-01-01', '2026-12-31'))->toBeFalse();

    // Nothing more to fetch, so no job goes out.
    Queue::assertNotPushed(SyncLeaseLedger::class);
});

test('asks only for the days since the last read', function () {
    $soap = Mockery::mock(PropertywareSoap::class);
    $soap->shouldReceive('leaseLedger')->once()->with(55, '2026-01-01', '2026-06-30')->andReturn([
        ledgerItem(1, '2026-01-01', 1400.0),
    ]);

    $store = new LeaseLedgerStore($soap);
    $store->sync(55, '2026-01-01', '2026-06-30');

    // A day passes, so the copy is read again — but only from a fortnight back.
    $this->travel(25)->hours();

    $soap->shouldReceive('leaseLedger')->once()->with(55, '2026-06-16', '2026-07-31')->andReturn([
        ledgerItem(2, '2026-07-01', 1400.0),
    ]);

    $store->sync(55, '2026-01-01', '2026-07-31');

    expect(LeaseLedgerEntry::where('lease_id', '55')->count())->toBe(2)
        ->and(LeaseLedgerSync::find('55')->synced_to->toDateString())->toBe('2026-07-31');
});

test('a re-read replaces an entry instead of doubling it', function () {
    $soap = Mockery::mock(PropertywareSoap::class);
    $soap->shouldReceive('leaseLedger')->twice()->andReturn(
        [ledgerItem(1, '2026-01-01', 1400.0)],
        // Propertyware corrected the amount since we last looked.
        [ledgerItem(1, '2026-01-01', 1450.0)],
    );

    $store = new LeaseLedgerStore($soap);
    $store->sync(55, '2026-01-01', '2026-06-30');
    $this->travel(25)->hours();
    $store->sync(55, '2026-01-01', '2026-06-30');

    $entries = LeaseLedgerEntry::where('lease_id', '55')->get();

    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->amount)->toBe(1450.0);
});

test('counts a returned payment as money going back out, not as rent billed', function () {
    $soap = Mockery::mock(PropertywareSoap::class);
    $soap->shouldReceive('leaseLedger')->once()->andReturn([
        ledgerItem(1, '2026-09-01', 2400.0),
        // The ePayment, and the same money taken back when it bounced.
        ledgerItem(2, '2026-09-01', -2400.0, 'Accounts Receivable', 'A/R'),
        ledgerItem(3, '2026-09-07', 2400.0, 'Accounts Receivable', 'A/R'),
    ]);

    $store = new LeaseLedgerStore($soap);
    $store->sync(55, '2026-09-01', '2026-09-30');

    $entries = $store->entries(55, '2026-09-01', '2026-09-30');

    expect($entries->pluck('kind', 'external_id')->all())->toBe([
        '3' => 'return',
        '2' => 'payment',
        '1' => 'charge',
    ])
        ->and($entries->firstWhere('kind', 'return')->description)->toBe('Payment returned');
});
