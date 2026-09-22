<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Jobs\WarmOwnerLedgerBalance;
use App\Models\ClientAccount;
use App\Services\Propertyware\Financials;
use App\Services\Propertyware\OwnerLedger;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerLedgerController extends Controller
{
    /**
     * What a portfolio carried in, earned, spent, and has left.
     *
     * The figures Propertyware's own owner portal opens with. One portfolio at
     * a time, as Propertyware does — a balance summed across portfolios is not
     * a balance of anything, so the client picks one and this answers for it.
     *
     * @throws PropertywareException
     */
    public function __invoke(Request $request, ClientAccount $account, PropertywareDirectory $directory, Financials $financials, OwnerLedger $ledger): JsonResponse
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        $validated = $request->validate([
            'portfolio' => ['nullable', 'integer'],
            // How many months of income against expense to chart.
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
            'properties' => ['nullable', 'array'],
            'properties.*' => ['integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $scope = $directory->scopeFor($account);
        $portfolioId = (int) ($validated['portfolio'] ?? $scope->portfolioIds[0] ?? 0);

        abort_if($portfolioId === 0 || ! in_array($portfolioId, $scope->portfolioIds, strict: true), 404);

        [$from, $to] = $financials->period($validated);

        // Propertyware's Account Balance runs from the portfolio's first day,
        // which costs a request per month of history. The job warms it; until
        // it has run, `fromInception` is false and the balance is this
        // period's movement rather than the true standing figure.
        WarmOwnerLedgerBalance::dispatch($portfolioId, $from);

        // The portfolio's own properties, named the way the owner knows them:
        // the building's name when Propertyware holds one, its address when not.
        //
        // Which buildings sit in this portfolio, in one read rather than a
        // lookup per property.
        $inPortfolio = collect($directory->buildingsInPortfolios([$portfolioId]))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $locations = collect($directory->propertiesFor($scope))
            ->filter(fn ($property): bool => in_array((int) $property->id, $inPortfolio, strict: true))
            ->mapWithKeys(fn ($property): array => [(int) $property->id => $property->name ?: $property->address])
            ->all();

        // Only the owner's own buildings, and only ones in this portfolio, so a
        // building id in the query can never reach anyone else's.
        $buildingIds = array_values(array_intersect(
            array_map(intval(...), $validated['properties'] ?? []),
            array_keys($locations),
        ));

        $period = $ledger->forPortfolio(
            $scope,
            $portfolioId,
            $from,
            $to,
            withOpeningBalance: true,
            buildingIds: $buildingIds,
            locations: $locations,
        );

        $names = collect($directory->portfoliosFor($scope))->pluck('name', 'id')->all();

        return response()->json([
            'data' => [
                'income' => $period['income'],
                'expense' => $period['expense'],
                'managementFee' => $period['managementFee'],
                'openingBalance' => $period['openingBalance'],
                'endingBalance' => $period['endingBalance'],
                'net' => $period['net'],
                // False while the balance walk is still queued, so the app can
                // say "this period" rather than quoting a balance that is not one.
                'fromInception' => $period['fromInception'],
                // The movement itself, newest first, with the running balance
                // each one left behind — the ledger as the owner portal lists it.
                'entries' => $period['entries'],
                // Income against expense, month by month. The balance walk has
                // already read these months, so they cost little once warm.
                'months' => $ledger->monthly($scope, $portfolioId, (int) ($validated['months'] ?? 12)),
            ],
            'meta' => [
                'portfolio' => [
                    'id' => $portfolioId,
                    'name' => $names[$portfolioId] ?? 'Portfolio '.$portfolioId,
                ],
                // Every portfolio the owner can ask for, so the app can offer them.
                'portfolios' => collect($scope->portfolioIds)
                    ->map(fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? 'Portfolio '.$id])
                    ->values()
                    ->all(),
                // What the property filter can offer, for this portfolio only.
                'properties' => collect($locations)
                    ->map(fn (string $label, int $id): array => ['id' => $id, 'name' => $label])
                    ->sortBy('name')
                    ->values()
                    ->all(),
                'from' => $period['from'],
                'to' => $period['to'],
            ],
        ]);
    }
}
