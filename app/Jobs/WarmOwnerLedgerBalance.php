<?php

namespace App\Jobs;

use App\Services\Propertyware\OwnerLedger;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Read a portfolio's history so its owner sees a real account balance.
 *
 * Propertyware's balance runs from the portfolio's first day and the API
 * refuses a window longer than 30 days, so a decade of books is a couple of
 * hundred requests. That is far too slow for a page, and the months never
 * change once closed, so it is read once here and cached.
 */
class WarmOwnerLedgerBalance implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(
        public readonly int $portfolioId,
        public readonly string $from,
    ) {}

    public function uniqueId(): string
    {
        return $this->portfolioId.':'.$this->from;
    }

    public function handle(OwnerLedger $ledger): void
    {
        set_time_limit($this->timeout);

        try {
            $ledger->warmBalance($this->portfolioId, $this->from);
        } catch (PropertywareException $exception) {
            // The page falls back to the period's own movement, so a failure
            // here costs accuracy of one figure rather than the whole ledger.
            Log::warning('Could not warm an owner ledger balance.', [
                'portfolio_id' => $this->portfolioId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
