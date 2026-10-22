<?php

use App\Jobs\SyncLeaseLedger;
use App\Models\LeaseLedgerEntry;
use App\Models\LeaseLedgerSync;
use App\Services\Propertyware\LeaseLedgerSource;
use App\Services\Propertyware\LeaseLedgerStore;
use Illuminate\Support\Facades\Queue;

function ledgerEntry(string $externalId, string $date, float $amount, string $kind = 'charge', string $description = 'Rent'): array
{
    return [
        'external_id' => $externalId,
        'occurred_on' => $date,
        'kind' => $kind,
        'direction' => $kind === 'payment' || $kind === 'credit' ? 'credit' : 'debit',
        'amount' => $amount,
        'description' => $description,
        'detail' => null,
        'reference' => null,
        'deposit_checked' => false,
        'deposited_on' => null,
    ];
}

test('never keeps a page waiting: the read is queued and served from our copy', function () {
    Queue::fake();
    $store = new LeaseLedgerStore(Mockery::mock(LeaseLedgerSource::class));

    expect($store->entries(55, '2025-01-01', '2026-12-31'))->toBeEmpty()
        ->and($store->isEmpty(55))->toBeTrue();

    Queue::assertPushed(SyncLeaseLedger::class, fn (SyncLeaseLedger $job): bool => $job->leaseId === 55);
});

test('reads a lease once, then leaves it alone for a day', function () {
    Queue::fake();
    $source = Mockery::mock(LeaseLedgerSource::class);
    $source->shouldReceive('entries')->once()->andReturn([
        ledgerEntry('charge-1', '2026-01-01', 1400.0),
        ledgerEntry('payment-2', '2026-01-03', 1400.0, 'payment', 'Payment'),
    ]);

    $store = new LeaseLedgerStore($source);
    $store->sync(55, '2025-01-01', '2026-12-31');

    expect($store->entries(55, '2025-01-01', '2026-12-31'))->toHaveCount(2)
        ->and($store->isStale(55, '2025-01-01', '2026-12-31'))->toBeFalse();

    // Nothing more to fetch, so no job goes out.
    Queue::assertNotPushed(SyncLeaseLedger::class);
});

test('widening the window re-reads the lease and keeps what it already held', function () {
    Queue::fake();
    $source = Mockery::mock(LeaseLedgerSource::class);

    // The REST endpoints answer per lease rather than per window, so the
    // second read returns the whole tenancy again and simply replaces what
    // is held rather than appending to it.
    $source->shouldReceive('entries')->twice()->with(55)->andReturn([
        ledgerEntry('charge-1', '2026-01-01', 1400.0),
        ledgerEntry('charge-2', '2026-07-01', 1400.0),
    ]);

    $store = new LeaseLedgerStore($source);
    $store->sync(55, '2026-01-01', '2026-06-30');

    expect($store->isStale(55, '2026-01-01', '2026-12-31'))->toBeTrue();

    $store->sync(55, '2026-01-01', '2026-12-31');

    expect(LeaseLedgerEntry::where('lease_id', '55')->count())->toBe(2)
        ->and(LeaseLedgerSync::find('55')->synced_to->toDateString())->toBe('2026-12-31')
        ->and($store->isStale(55, '2026-01-01', '2026-12-31'))->toBeFalse();
});

test('a re-read replaces an entry instead of doubling it', function () {
    $source = Mockery::mock(LeaseLedgerSource::class);
    $source->shouldReceive('entries')->twice()->andReturn(
        [ledgerEntry('charge-1', '2026-01-01', 1400.0)],
        // Propertyware corrected the amount since we last looked.
        [ledgerEntry('charge-1', '2026-01-01', 1450.0)],
    );

    $store = new LeaseLedgerStore($source);
    $store->sync(55, '2026-01-01', '2026-06-30');
    $this->travel(25)->hours();
    $store->sync(55, '2026-01-01', '2026-06-30');

    $entries = LeaseLedgerEntry::where('lease_id', '55')->get();

    expect($entries)->toHaveCount(1)
        ->and((float) $entries->first()->amount)->toBe(1450.0);
});
