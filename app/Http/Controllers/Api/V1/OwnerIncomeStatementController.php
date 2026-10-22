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
 * for the app to show or to save to the phone as a PDF.
 */
class OwnerIncomeStatementController extends Controller
{
    /**
     * @throws PropertywareException
     */
    public function show(Request $request, ClientAccount $account, PropertywareDirectory $directory, IncomeStatement $statements, OwnerStatement $monthly): JsonResponse
    {
        [$scope, $portfolioId, $year] = $this->resolve($request, $account, $directory, $statements);

        return response()->json([
            'data' => $statements->for($scope, $portfolioId, $year),
            'meta' => [
                'years' => $statements->years(),
                'portfolios' => $monthly->portfolios($scope),
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
        [$scope, $portfolioId, $year] = $this->resolve($request, $account, $directory, $statements);

        return $statements->download($statements->for($scope, $portfolioId, $year));
    }

    /**
     * @return array{0: ClientScope, 1: int, 2: int}
     *
     * @throws PropertywareException
     */
    private function resolve(Request $request, ClientAccount $account, PropertywareDirectory $directory, IncomeStatement $statements): array
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        $validated = $request->validate([
            'portfolio' => ['nullable', 'integer'],
            'year' => ['nullable', 'integer'],
        ]);

        $scope = $directory->scopeFor($account);
        $portfolioId = (int) ($validated['portfolio'] ?? $scope->portfolioIds[0] ?? 0);

        abort_if($portfolioId === 0 || ! in_array($portfolioId, $scope->portfolioIds, strict: true), 404);

        return [$scope, $portfolioId, $statements->yearOrDefault(isset($validated['year']) ? (int) $validated['year'] : null)];
    }
}
