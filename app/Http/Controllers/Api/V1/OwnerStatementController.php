<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Propertyware\OwnerStatement;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OwnerStatementController extends Controller
{
    /**
     * One portfolio's statement for one finished month, with the months and
     * portfolios the app can offer instead.
     *
     * @throws PropertywareException
     */
    public function __invoke(Request $request, ClientAccount $account, PropertywareDirectory $directory, OwnerStatement $statements): JsonResponse
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        $validated = $request->validate([
            'portfolio' => ['nullable', 'integer'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $scope = $directory->scopeFor($account);
        $portfolioId = (int) ($validated['portfolio'] ?? $scope->portfolioIds[0] ?? 0);

        abort_if($portfolioId === 0 || ! in_array($portfolioId, $scope->portfolioIds, strict: true), 404);

        return response()->json([
            'data' => $statements->for($scope, $portfolioId, $statements->monthOrDefault($validated['month'] ?? null)),
            'meta' => [
                'portfolios' => $statements->portfolios($scope),
                'months' => $statements->months(),
            ],
        ]);
    }
}
