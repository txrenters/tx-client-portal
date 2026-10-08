<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\LeaseLedgerRequest;
use App\Models\ClientAccount;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    /**
     * A tenant's charges and payments, with what their lease says they owe,
     * optionally narrowed to a period with `from` and `to`.
     *
     * @throws PropertywareException
     */
    public function __invoke(LeaseLedgerRequest $request, ClientAccount $account, PropertywareDirectory $directory, LeaseLedger $ledger): JsonResponse
    {
        abort_unless($account->role === ClientRole::Resident, 404);

        $filters = $request->filters();
        $scope = $directory->scopeFor($account);
        $entries = $ledger->forTenant($scope, from: $filters['from'], to: $filters['to'], page: $request->page());

        abort_if($entries === null, 404);

        return response()->json([
            'data' => $entries['entries'],
            'meta' => [
                'currentPage' => $entries['results']['current_page'],
                'lastPage' => $entries['results']['last_page'],
                'total' => $entries['results']['total'],
                'charged' => $entries['charged'],
                'paid' => $entries['paid'],
                'months' => $entries['months'],
                'balance' => $directory->leaseFor($scope)?->balance,
                'from' => $entries['from'],
                'to' => $entries['to'],
            ],
        ]);
    }
}
