<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Jobs\ImportOwnerLedgers;
use App\Jobs\WarmOwnerLedgerBalance;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\OwnerLedger;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinancialController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show what the owner has been billed, by portfolio.
     *
     * Propertyware gives us bills but no income ledger, so this is the expense
     * side; the page says as much rather than implying a full statement.
     */
    public function __invoke(Request $request, PropertywareDirectory $directory, Financials $financials, OwnerLedger $ledger): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        $validated = $request->validate([
            'portfolios' => ['nullable', 'array'],
            'portfolios.*' => ['integer'],
            'properties' => ['nullable', 'array'],
            'properties.*' => ['integer'],
            'status' => ['nullable', 'in:all,paid,unpaid'],
            'page' => ['nullable', 'integer', 'min:1'],
            // Which preset the page shows; the dates do the filtering.
            'range' => ['nullable', 'string', 'max:20'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $filters = [
            // Query strings arrive as strings and the "integer" rule validates
            // without casting, so these are cast here. The page compares them
            // against numeric ids with ===, and a string never matches: that is
            // what made picking a portfolio empty the properties menu.
            'portfolios' => array_map(intval(...), $validated['portfolios'] ?? []),
            'properties' => array_map(intval(...), $validated['properties'] ?? []),
            'status' => $validated['status'] ?? 'all',
            'range' => $validated['range'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];

        // Opening the page starts the import; nothing here waits on it.
        try {
            $scope = $this->clientScope($request, ClientRole::Owner, $directory);
            // The import covers the widest window the page can ask for, not
            // whichever one it happens to be showing.
            ImportOwnerLedgers::dispatch($scope, ...$financials->period([]));
        } catch (PropertywareException) {
            // Without a scope there is nothing to import; the page says so itself.
        }

        return Inertia::render('owner/Financials', [
            'filters' => $filters,
            // The ledger Propertyware's own owner portal shows: what came in,
            // what went out, and what it left. One portfolio at a time, as
            // Propertyware does, because a balance across portfolios is not a
            // figure the owner is ever shown anywhere else.
            'ledger' => Inertia::defer(function () use ($request, $directory, $financials, $ledger, $filters): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    $portfolioId = (int) ($filters['portfolios'][0] ?? $scope->portfolioIds[0] ?? 0);

                    if ($portfolioId === 0) {
                        return null;
                    }

                    [$from, $to] = $financials->period($filters);

                    // Reading the portfolio's whole history is a queued job;
                    // until it has run the ledger shows this period's movement.
                    WarmOwnerLedgerBalance::dispatch($portfolioId, $from);

                    // Only the owner's own properties can be filtered to, so a
                    // building id in the query cannot reach anyone else's.
                    $buildingIds = array_values(array_intersect(
                        array_map(intval(...), $filters['properties']),
                        $scope->buildingIds,
                    ));

                    $locations = collect($directory->propertiesFor($scope))
                        ->mapWithKeys(fn ($property): array => [
                            (int) $property->id => $property->name ?: $property->address,
                        ])
                        ->all();

                    return $ledger->forPortfolio(
                        $scope,
                        $portfolioId,
                        $from,
                        $to,
                        withOpeningBalance: true,
                        buildingIds: $buildingIds,
                        locations: $locations,
                    );
                } catch (PropertywareException) {
                    return null;
                }
            }),
            'portfolios' => Inertia::defer(function () use ($request, $directory): array {
                try {
                    return $directory->portfolioOptionsFor($this->clientScope($request, ClientRole::Owner, $directory));
                } catch (PropertywareException) {
                    return [];
                }
            }, 'options'),
            'properties' => Inertia::defer(function () use ($request, $directory): array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Owner, $directory);
                    // Which portfolio each building sits in, so ticking a portfolio
                    // narrows this menu to its own properties.
                    $portfolios = collect($directory->findBuildings($scope->buildingIds))
                        ->mapWithKeys(fn (array $building): array => [(int) ($building['id'] ?? 0) => (int) ($building['portfolioID'] ?? 0)]);

                    return collect($directory->propertiesFor($scope))
                        ->unique(fn ($property): int => $property->id)
                        ->map(fn ($property): array => [
                            'id' => $property->id,
                            'name' => $property->name ?: $property->address,
                            'portfolioId' => $portfolios->get($property->id),
                        ])
                        ->sortBy('name')
                        ->values()
                        ->all();
                } catch (PropertywareException) {
                    return [];
                }
            }, 'options'),
            'revenue' => Inertia::defer(function () use ($request, $directory, $financials, $filters): ?array {
                try {
                    return $financials->revenueByProperty($this->clientScope($request, ClientRole::Owner, $directory), $filters);
                } catch (PropertywareException) {
                    return null;
                }
            }, 'revenue'),
            'financials' => Inertia::defer(function () use ($request, $directory, $financials, $filters, $validated): ?array {
                try {
                    return $financials->forOwner(
                        $this->clientScope($request, ClientRole::Owner, $directory),
                        $filters,
                        (int) ($validated['page'] ?? 1),
                        $request->url(),
                    );
                } catch (PropertywareException) {
                    return null;
                }
            }),
        ]);
    }
}
