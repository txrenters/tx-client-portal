<?php

namespace App\Services\Propertyware;

use App\Jobs\SyncLeaseLedger;
use App\Models\LeaseLedgerEntry;
use App\Models\LeaseLedgerSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The portal's own copy of a lease's ledger, kept up to date as people visit.
 *
 * A ledger is spread across five REST endpoints, so reading one costs five
 * round trips however small the tenancy. The entries are stored here instead:
 * a visit reads the database, and Propertyware is only asked again once the
 * copy goes stale. Propertyware stays the source of truth — nothing is ever
 * written back, and a re-read of an entry replaces the copy rather than
 * adding to it.
 */
class LeaseLedgerStore
{
    /**
     * How long a lease's copy is trusted before it is refreshed. The first read
     * takes the whole history; after that a day's worth of new entries is all
     * that ever needs fetching.
     */
    private const FRESH_MINUTES = 1440;

    /**
     * Entries written per statement. One round trip per chunk rather than one
     * per entry, which is the difference between a tenancy importing in a
     * moment and a worker running out of memory part way through an owner.
     */
    private const WRITE_CHUNK = 500;

    public function __construct(private LeaseLedgerSource $source) {}

    /**
     * The lease's entries for a window, as we hold them now.
     *
     * Nothing is fetched here: a read that would go to Propertyware is queued
     * instead, so a page never waits on a tenancy's worth of API calls. The
     * page shows what is held and says the rest is on its way.
     *
     * @return Collection<int, LeaseLedgerEntry>
     */
    public function entries(int $leaseId, string $from, string $to): Collection
    {
        if ($this->isStale($leaseId, $from, $to)) {
            SyncLeaseLedger::dispatch($leaseId, $from, $to);
        }

        return LeaseLedgerEntry::query()
            ->where('lease_id', (string) $leaseId)
            ->whereBetween('occurred_on', [$from, $to])
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Whether a lease's copy is worth refreshing.
     */
    public function isStale(int $leaseId, string $from, string $to): bool
    {
        $state = LeaseLedgerSync::find((string) $leaseId);

        return $state === null || ! $this->isFresh($state, $from, $to);
    }

    /**
     * Whether we hold nothing at all for a lease yet, so a page has numbers
     * still to come rather than numbers that are merely a day old.
     */
    public function isEmpty(int $leaseId): bool
    {
        return ! LeaseLedgerEntry::query()->where('lease_id', (string) $leaseId)->exists();
    }

    /**
     * Fetch what we don't hold yet: the first read covers the whole window,
     * later ones only the days since we last looked.
     *
     * @throws PropertywareException
     */
    public function sync(int $leaseId, string $from, string $to): void
    {
        $state = LeaseLedgerSync::find((string) $leaseId);

        if ($state !== null && $this->isFresh($state, $from, $to)) {
            return;
        }

        // The REST endpoints answer per lease rather than per window, so one
        // sync reads the whole tenancy and any window the visitor asks for is
        // already covered.
        $now = now();
        $batch = [];

        foreach ($this->source->entries($leaseId) as $entry) {
            $batch[] = [...$entry, 'lease_id' => (string) $leaseId, 'created_at' => $now, 'updated_at' => $now];

            if (count($batch) >= self::WRITE_CHUNK) {
                $this->write($batch);
                $batch = [];
            }
        }

        $this->write($batch);

        LeaseLedgerSync::updateOrCreate(['lease_id' => (string) $leaseId], [
            'synced_from' => $state === null ? $from : min($from, $state->synced_from->toDateString()),
            'synced_to' => $to,
            'synced_at' => now(),
        ]);
    }

    /**
     * Write a chunk, replacing any entry we already hold rather than doubling it.
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function write(array $batch): void
    {
        if ($batch === []) {
            return;
        }

        LeaseLedgerEntry::query()->upsert(
            $batch,
            ['lease_id', 'external_id'],
            ['occurred_on', 'kind', 'direction', 'amount', 'description', 'detail', 'reference', 'updated_at'],
        );
    }

    /**
     * Whether the copy already covers this window and was read recently.
     */
    private function isFresh(LeaseLedgerSync $state, string $from, string $to): bool
    {
        return $state->synced_from->lte(CarbonImmutable::parse($from))
            && $state->synced_to->gte(CarbonImmutable::parse($to))
            && $state->synced_at->gt(now()->subMinutes(self::FRESH_MINUTES));
    }
}
