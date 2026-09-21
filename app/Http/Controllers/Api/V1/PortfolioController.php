<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;

class PortfolioController extends Controller
{
    /**
     * Show an owner's portfolios and occupancy; residents have no portfolio.
     *
     * @throws PropertywareException
     */
    public function __invoke(ClientAccount $account, PropertywareDirectory $directory): JsonResponse
    {
        abort_unless($account->role === ClientRole::Owner, 404);

        $scope = $directory->scopeFor($account);

        return response()->json(['data' => [
            'portfolios' => $directory->portfoliosFor($scope),
            'occupancy' => $directory->occupancyFor($scope),
            'contactDetails' => $directory->contactDetailsFor($scope),
        ]]);
    }
}
