<?php

namespace App\Jobs;

use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\OwnerLedger;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Read one portfolio's income and expense for a period, so the list of
 * portfolios quotes exactly what the portfolio's own card does.
 *
 * One job per portfolio rather than a loop inside ImportOwnerLedgers: an owner
 * with ninety-four of them would otherwise add ninety-four general-ledger walks
 * to a job that already reads every bill and every lease they hold.
 */
class WarmPortfolioIncome implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public readonly ClientScope $scope,
        public readonly int $portfolioId,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function uniqueId(): string
    {
        return $this->portfolioId.':'.$this->from.':'.$this->to;
    }

    public function handle(OwnerLedger $ledger): void
    {
        set_time_limit($this->timeout);

        try {
            $ledger->warmSummary($this->scope, $this->portfolioId, $this->from, $this->to);
        } catch (PropertywareException $exception) {
            // The row shows a dash until this succeeds, which is honest; the
            // portfolio's own card still reads its ledger directly.
            Log::warning('Could not warm a portfolio ledger summary.', [
                'portfolio_id' => $this->portfolioId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
