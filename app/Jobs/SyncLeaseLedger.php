<?php

namespace App\Jobs;

use App\Services\Propertyware\LeaseLedgerStore;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Read a lease's ledger from Propertyware in the background.
 *
 * A first read covers the whole tenancy, which is one SOAP call per ten
 * entries — far too long to keep someone waiting on a page. The page shows
 * what we already hold and says the rest is on its way; this fetches it.
 */
class SyncLeaseLedger implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Two of these for the same lease would read the same entries twice. */
    public int $uniqueFor = 600;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(
        public int $leaseId,
        public string $from,
        public string $to,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->leaseId;
    }

    /**
     * @throws PropertywareException
     */
    public function handle(LeaseLedgerStore $store): void
    {
        // Reading a tenancy is minutes of round trips; the worker's own timeout
        // governs it, not whatever php.ini sets for a web request.
        set_time_limit($this->timeout);

        $store->sync($this->leaseId, $this->from, $this->to);
    }
}
