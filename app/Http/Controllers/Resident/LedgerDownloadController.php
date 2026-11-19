<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\LedgerDownloadRequest;
use App\Services\Propertyware\LeaseLedger;
use App\Services\Propertyware\LedgerDownloads;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Symfony\Component\HttpFoundation\Response;

class LedgerDownloadController extends Controller
{
    use ResolvesClientScope;

    /**
     * Download the tenant's ledger for the period on the Lease page, as a PDF or CSV.
     *
     * @throws PropertywareException
     */
    public function __invoke(LedgerDownloadRequest $request, PropertywareDirectory $directory, LeaseLedger $ledger, LedgerDownloads $downloads): Response
    {
        abort_if($this->activeClientAccount($request, ClientRole::Resident) === null, 403);

        $scope = $this->clientScope($request, ClientRole::Resident, $directory);
        $filters = $request->filters();
        $export = $ledger->export($scope, from: $filters['from'], to: $filters['to']);

        abort_if($export === null, 404);

        $property = $directory->propertiesFor($scope)[0] ?? null;

        return $downloads->resident($export, $property?->address ?: $property?->name, $request->fileType());
    }
}
