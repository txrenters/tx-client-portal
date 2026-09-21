<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\PropertyIndexRequest;
use App\Http\Requests\Client\UploadDocumentRequest;
use App\Services\Listings\ListingPhotos;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    use ResolvesClientScope;

    /**
     * List the owner's properties, one page at a time.
     */
    public function index(PropertyIndexRequest $request, PropertywareDirectory $directory): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        $filters = $request->filters();

        return Inertia::render('owner/Properties', [
            'filters' => $filters,
            'properties' => Inertia::defer(function () use ($request, $directory, $filters): ?array {
                try {
                    return $directory->paginateProperties(
                        $this->clientScope($request, ClientRole::Owner, $directory),
                        $filters,
                        $request->page(),
                        path: $request->url(),
                    );
                } catch (PropertywareException) {
                    return null;
                }
            }),
        ]);
    }

    /**
     * Show one of the owner's properties.
     */
    public function show(Request $request, string $property, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents, ListingPhotos $photos, ConversationLibrary $conversations): Response
    {
        $scope = $this->clientScope($request, ClientRole::Owner, $directory);

        $details = $directory->propertyFor($scope, (int) $property);

        abort_if($details === null, 404);

        return Inertia::render('owner/PropertyDetail', [
            'property' => $details->toArray(),
            'details' => $directory->detailsFor($scope, (int) $property),
            'maxUploadMegabytes' => UploadDocumentRequest::MAX_KILOBYTES / 1024,
            'photos' => Inertia::defer(fn (): array => $photos->for($details), 'photos'),
            'maintenance' => Inertia::defer(fn (): array => $maintenance->paginate(
                $scope,
                ['property' => $details->fullAddress()],
                perPage: 10,
                path: route('owner.maintenance.index'),
            )),
            'documents' => Inertia::defer(function () use ($scope, $property, $documents): ?array {
                try {
                    return $documents->pageOrImport($scope, ['property' => (int) $property], path: route('owner.documents'));
                } catch (PropertywareException) {
                    return null;
                }
            }),
            'conversations' => Inertia::defer(function () use ($scope, $property, $conversations): ?array {
                try {
                    return $conversations->forBuilding($scope, (int) $property);
                } catch (PropertywareException) {
                    return null;
                }
            }),
        ]);
    }
}
