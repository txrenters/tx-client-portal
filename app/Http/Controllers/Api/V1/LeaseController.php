<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;

class LeaseController extends Controller
{
    /**
     * Show a tenant their lease; owners hold no lease of their own.
     *
     * Propertyware has no ledger we can read, so this is the lease as it
     * stands: its term, the rent, and what is owed on it.
     *
     * @throws PropertywareException
     */
    public function __invoke(ClientAccount $account, PropertywareDirectory $directory): JsonResponse
    {
        abort_unless($account->role === ClientRole::Resident, 404);

        $lease = $directory->leaseFor($directory->scopeFor($account));

        abort_if($lease === null, 404);

        return response()->json(['data' => $lease->toArray()]);
    }
}
