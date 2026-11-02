<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\IncomeStatement;
use App\Services\Propertyware\OwnerStatement;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An owner's income statement for one portfolio and one year, month by month,
 * for the app to show or to save to the phone as a PDF. An owner with more
 * than one property in the portfolio can narrow it to one of them.
 */
class OwnerIncomeStatementController extends Controller
{
    /**
     * @throws PropertywareException
     */
    public function show(Request $request, ClientAccount $account, PropertywareDirectory $directory, IncomeStatement $statements, OwnerStatement $monthly): JsonResponse
    {
        [$scope, $portfolioId, $from, $to, $property, $properties] = $this->resolve($request, $account, $directory, $statements);

        return response()->json([
            'data' => $statements->for($scope, $portfolioId, $from, $to, $property),
            'meta' => [
                'years' => $statements->years(),
                // The months a PDF can be made between, up to MAX_MONTHS at a time.
                'range' => [...$statements->range(), 'maxMonths' => IncomeStatement::MAX_MONTHS],
                'portfolios' => $monthly->portfolios($scope),
                // What the property filter can offer, for this portfolio only.
                'properties' => collect($properties)
                    ->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name])
                    ->sortBy('name')
                    ->values()
                    ->all(),
            ],
        ]);
    }

    /**
     * The same statement as a PDF, made for this request and never kept.
     *
     * @throws PropertywareException
     */
    public function pdf(Request $request, ClientAccount $account, PropertywareDirectory $directory, IncomeStatement $statements): Response
    {
        [$scope, $portfolioId, $from, $to, $property] = $this->resolve($request, $account, $directory, $statements);

        return $statements->download($statements->for($scope, $portfolioId, $from, $to, $property));
    }

    /**
     * @return array{0: ClientScope, 1: int, 2: string, 3: string, 4: array{id: int, name: string}|null, 5: array<int, string>}
     *
     * @throws PropertywareException
     */
    private function resolve(Request $request, ClientAccount $account, PropertywareDirectory $directory, IncomeStatement $statements): array
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        $validated = $request->validate([
            'portfolio' => ['nullable', 'integer'],
            'property' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
        ]);
        $period = $statements->period($validated);

        $scope = $directory->scopeFor($account);
        $portfolioId = (int) ($validated['portfolio'] ?? $scope->portfolioIds[0] ?? 0);

        abort_if($portfolioId === 0 || ! in_array($portfolioId, $scope->portfolioIds, strict: true), 404);

        $properties = $directory->propertyNamesInPortfolio($scope, $portfolioId);
        $property = null;

        if (isset($validated['property'])) {
            $id = (int) $validated['property'];

            abort_unless(array_key_exists($id, $properties), 404);

            $property = ['id' => $id, 'name' => $properties[$id]];
        }

        return [$scope, $portfolioId, ...$period, $property, $properties];
    }
}
