<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;

class ContactDetailsController extends Controller
{
    /**
     * Show what TexasRenters.com has on record for the client, grouped into sections.
     *
     * @throws PropertywareException
     */
    public function __invoke(ClientAccount $account, PropertywareDirectory $directory): JsonResponse
    {
        return response()->json([
            'data' => $directory->contactDetailsFor($directory->scopeFor($account)),
        ]);
    }
}
