<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\PropertyIndexRequest;
use App\Models\ClientAccount;
use App\Services\Listings\ListingPhotos;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertyTimeline;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;

class PropertyController extends Controller
{
    /**
     * List one page of the properties the account owns or rents, optionally filtered.
     *
     * @throws PropertywareException
     */
    public function index(PropertyIndexRequest $request, ClientAccount $account, PropertywareDirectory $directory): JsonResponse
    {
        $result = $directory->paginateProperties($directory->scopeFor($account), $request->filters(), $request->page(), path: $request->url());
        $page = $result['results'];

        return response()->json([
            'data' => $page['data'],
            'meta' => [
                'currentPage' => $page['current_page'],
                'lastPage' => $page['last_page'],
                'perPage' => $page['per_page'],
                'total' => $page['total'],
                'unfilteredTotal' => $result['total'],
                'counts' => $result['counts'],
                'cities' => $result['cities'],
            ],
        ]);
    }

    /**
     * Show one property with its maintenance, documents and history, or 404 when it isn't the account's.
     *
     * @throws PropertywareException
     */
    public function show(ClientAccount $account, int $property, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents, PropertyTimeline $timeline, ListingPhotos $photos): JsonResponse
    {
        $scope = $directory->scopeFor($account);

        $details = $directory->propertyFor($scope, $property);

        abort_if($details === null, 404);

        $latest = $maintenance->paginate($scope, ['property' => $details->fullAddress()], perPage: 10);

        if ($latest['status'] === 'error') {
            throw new PropertywareException('Maintenance requests could not be read.');
        }

        return response()->json(['data' => [
            ...$details->toArray(),
            'details' => $directory->detailsFor($scope, $property),
            'photos' => $photos->for($details),
            'maintenance' => [
                'counts' => $maintenance->groupsForBuilding($scope, $property)['counts'],
                'total' => $latest['workOrders']['total'],
                'latest' => $latest['workOrders']['data'],
            ],
            'documents' => ($page = $documents->pageOrImport($scope, ['property' => $property]))['status'] === 'ready' ? $page['results']['data'] : null,
            'documentsStatus' => $page['status'],
            'history' => $timeline->forProperty($scope, $property),
        ]]);
    }
}
