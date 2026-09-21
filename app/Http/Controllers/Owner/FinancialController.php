<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Jobs\ImportOwnerLedgers;
use App\Services\Propertyware\Financials;
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
    public function __invoke(Request $request, PropertywareDirectory $directory, Financials $financials): Response
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
            'portfolios' => $validated['portfolios'] ?? [],
            'properties' => $validated['properties'] ?? [],
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
