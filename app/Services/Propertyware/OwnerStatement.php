<?php

namespace App\Services\Propertyware;

use App\Jobs\WarmOwnerLedgerBalance;
use App\Services\Propertyware\Data\ClientScope;
use Carbon\CarbonImmutable;

/**
 * An owner's monthly statement for one portfolio: what it carried in, earned,
 * spent and paid out, account by account, and every line behind those figures.
 *
 * Built from the same ledger as the Financials page, so the two always agree.
 * Web and API both go through here.
 */
class OwnerStatement
{
    /** How many finished months an owner can pick from. */
    public const MONTHS_OFFERED = 24;

    public function __construct(private OwnerLedger $ledger, private PropertywareDirectory $directory) {}

    /**
     * The months a statement can be read for, newest first. Only finished
     * months: a statement for the month in progress would change under the reader.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function months(): array
    {
        $lastMonth = CarbonImmutable::now(config('app.display_timezone'))->startOfMonth()->subMonth();

        return collect(range(0, self::MONTHS_OFFERED - 1))
            ->map(fn (int $back): CarbonImmutable => $lastMonth->subMonths($back))
            ->map(fn (CarbonImmutable $month): array => ['value' => $month->format('Y-m'), 'label' => $month->format('F Y')])
            ->all();
    }

    /**
     * The month asked for when it is one on offer, or last month.
     */
    public function monthOrDefault(?string $month): string
    {
        $offered = array_column($this->months(), 'value');

        return in_array($month, $offered, strict: true) ? $month : $offered[0];
    }

    /**
     * The portfolios the owner can read a statement for.
     *
     * @return array<int, array{id: int, name: string}>
     *
     * @throws PropertywareException
     */
    public function portfolios(ClientScope $scope): array
    {
        $names = collect($this->directory->portfoliosFor($scope))->pluck('name', 'id')->all();

        return collect($scope->portfolioIds)
            ->map(fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? 'Portfolio '.$id])
            ->values()
            ->all();
    }

    /**
     * One portfolio's statement for one month, its lines oldest first.
     *
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    public function for(ClientScope $scope, int $portfolioId, string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, config('app.display_timezone'));
        $from = $start->startOfMonth()->toDateString();
        $to = $start->endOfMonth()->toDateString();

        $inPortfolio = collect($this->directory->buildingsInPortfolios([$portfolioId]))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $locations = collect($this->directory->propertiesFor($scope))
            ->filter(fn ($property): bool => in_array((int) $property->id, $inPortfolio, strict: true))
            ->mapWithKeys(fn ($property): array => [(int) $property->id => $property->name ?: $property->address])
            ->all();

        $book = $this->ledger->forPortfolio($scope, $portfolioId, $from, $to, withOpeningBalance: true, locations: $locations);

        // The opening balance runs from the portfolio's first day, read by a
        // queued job; until it has run, the statement says it is not final.
        if (! $book['fromInception']) {
            WarmOwnerLedgerBalance::dispatch($portfolioId, $from);
        }

        $portfolio = collect($this->portfolios($scope))->firstWhere('id', $portfolioId);

        return [
            'portfolio' => $portfolio,
            'month' => $month,
            'label' => $start->format('F Y'),
            'from' => $from,
            'to' => $to,
            'openingBalance' => $book['openingBalance'],
            'income' => $book['income'],
            'expense' => $book['expense'],
            'managementFee' => $book['managementFee'],
            'draws' => $book['draws'],
            'endingBalance' => $book['endingBalance'],
            'fromInception' => $book['fromInception'],
            'incomeAccounts' => $book['incomeByAccount'],
            'expenseAccounts' => $book['expenseByAccount'],
            'entries' => array_reverse($book['entries']),
        ];
    }
}
