<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Propertyware\IncomeStatement;
use App\Services\Propertyware\OwnerStatement;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatementController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show the owner's statement for one portfolio and one finished month.
     */
    public function __invoke(Request $request, PropertywareDirectory $directory, OwnerStatement $statements, IncomeStatement $incomeStatements): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        $validated = $request->validate([
            'portfolio' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $statements->monthOrDefault($validated['month'] ?? null);
        $portfolio = isset($validated['portfolio']) ? (int) $validated['portfolio'] : null;

        return Inertia::render('owner/Statement', [
            'filters' => ['portfolio' => $portfolio, 'month' => $month],
            'months' => $statements->months(),
            // The months an income statement PDF can be made between, up to twelve at a time.
            'pdfRange' => [...$incomeStatements->range(), 'maxMonths' => IncomeStatement::MAX_MONTHS],
            'portfolios' => Inertia::defer(function () use ($request, $directory, $statements): array {
                try {
                    return $statements->portfolios($this->clientScope($request, ClientRole::Owner, $directory));
                } catch (PropertywareException) {
                    return [];
                }
            }, 'options'),
            // The selected portfolio's properties, so the income statement PDF
            // can be made for one of them. The page reloads on a portfolio change.
            'properties' => Inertia::defer(function () use ($request, $directory, $portfolio): array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    $portfolioId = in_array($portfolio, $scope->portfolioIds, strict: true) ? $portfolio : ($scope->portfolioIds[0] ?? null);

                    return $portfolioId === null ? [] : collect($directory->propertyNamesInPortfolio($scope, $portfolioId))
                        ->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name])
                        ->sortBy('name')
                        ->values()
                        ->all();
                } catch (PropertywareException) {
                    return [];
                }
            }, 'options'),
            'statement' => Inertia::defer(function () use ($request, $directory, $statements, $portfolio, $month): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    // Only the owner's own portfolios, so an id in the query can never reach anyone else's.
                    $portfolioId = in_array($portfolio, $scope->portfolioIds, strict: true) ? $portfolio : ($scope->portfolioIds[0] ?? null);

                    return $portfolioId === null ? null : $statements->for($scope, $portfolioId, $month);
                } catch (PropertywareException) {
                    return null;
                }
            }),
        ]);
    }
}
