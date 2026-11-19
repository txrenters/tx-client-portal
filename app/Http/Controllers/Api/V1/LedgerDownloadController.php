<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\LedgerDownloadRequest;
use App\Models\ClientAccount;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\LedgerDownloads;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Symfony\Component\HttpFoundation\Response;

class LedgerDownloadController extends Controller
{
    /**
     * A tenant's charges and payments as a PDF or CSV, for the app to save or
     * share, over the same `from` and `to` period as the payments list.
     *
     * @throws PropertywareException
     */
    public function __invoke(LedgerDownloadRequest $request, ClientAccount $account, PropertywareDirectory $directory, LeaseLedger $ledger, LedgerDownloads $downloads): Response
    {
        abort_unless($account->role === ClientRole::Resident, 404);

        $scope = $directory->scopeFor($account);
        $filters = $request->filters();
        $export = $ledger->export($scope, from: $filters['from'], to: $filters['to']);

        abort_if($export === null, 404);

        $property = $directory->propertiesFor($scope)[0] ?? null;

        return $downloads->resident($export, $property?->address ?: $property?->name, $request->fileType());
    }
}
