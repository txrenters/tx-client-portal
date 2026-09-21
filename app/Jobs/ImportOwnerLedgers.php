<?php

namespace App\Jobs;

use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\LeaseLedgerStore;
use App\Services\Propertyware\PropertywareClient;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Read every lease an owner holds, so their financials are ready when asked.
 *
 * Opening the page starts this rather than waiting on it: each lease is one
 * SOAP call per ten entries, and an owner with fifty properties would sit
 * through minutes of them. The page shows what is held and says the rest is
 * coming; this fetches the lot in the background.
 */
class ImportOwnerLedgers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Long enough that a visit during the import doesn't start a second one. */
    public int $uniqueFor = 1800;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public ClientScope $scope,
        public string $from,
        public string $to,
    ) {}

    public function uniqueId(): string
    {
        return $this->scope->contactId;
    }

    /**
     * Whether an owner's import is still running.
     */
    public static function isRunning(string $contactId): bool
    {
        return Cache::has(self::cacheKey($contactId));
    }

    public static function cacheKey(string $contactId): string
    {
        return "financials:importing:{$contactId}";
    }

    /**
     * @throws PropertywareException
     */
    public function handle(PropertywareClient $propertyware, LeaseLedgerStore $store, Financials $financials): void
    {
        // Reading a tenancy is minutes of round trips; the worker's own timeout
        // governs it, not whatever php.ini sets for a web request.
        set_time_limit($this->timeout);

        Cache::put(self::cacheKey($this->scope->contactId), true, now()->addMinutes(30));

        try {
            // Bills first: they are what the page leads with.
            foreach ($this->scope->portfolioIds as $portfolioId) {
                $financials->readBills($portfolioId);
            }

            foreach ($this->scope->buildingIds as $buildingId) {
                $leases = $propertyware->list('leases', ['buildingID' => $buildingId], 100, 0);

                foreach ($leases as $lease) {
                    if ($this->ranInPeriod($lease)) {
                        $store->sync((int) $lease['id'], $this->from, $this->to);
                    }
                }
            }
        } finally {
            Cache::forget(self::cacheKey($this->scope->contactId));
        }
    }

    /**
     * @param  array<string, mixed>  $lease
     */
    private function ranInPeriod(array $lease): bool
    {
        $start = substr((string) ($lease['startDate'] ?? ''), 0, 10);
        $end = substr((string) ($lease['moveOutDate'] ?? $lease['endDate'] ?? ''), 0, 10);

        return ($start === '' || $start <= $this->to) && ($end === '' || $end >= $this->from);
    }
}
