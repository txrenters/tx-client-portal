<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * A tenant's charges and payments, with what their lease says they owe.
     *
     * @throws PropertywareException
     */
    public function __invoke(Request $request, ClientAccount $account, PropertywareDirectory $directory, LeaseLedger $ledger): JsonResponse
    {
        abort_unless($account->role === ClientRole::Resident, 404);

        $validated = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $scope = $directory->scopeFor($account);
        $entries = $ledger->forTenant($scope, page: (int) ($validated['page'] ?? 1));

        abort_if($entries === null, 404);

        return response()->json([
            'data' => $entries['entries'],
            'meta' => [
                'currentPage' => $entries['results']['current_page'],
                'lastPage' => $entries['results']['last_page'],
                'total' => $entries['results']['total'],
                'charged' => $entries['charged'],
                'paid' => $entries['paid'],
                'balance' => $directory->leaseFor($scope)?->balance,
                'from' => $entries['from'],
                'to' => $entries['to'],
            ],
        ]);
    }
}
