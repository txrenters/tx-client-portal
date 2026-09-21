<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ResolvesClientScope;
use App\Http\Controllers\Controller;
use App\Services\Listings\ListingPhotos;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    use ResolvesClientScope;

    /**
     * Show the home the tenant rents.
     */
    public function show(Request $request, PropertywareDirectory $directory, DocumentLibrary $documents, ListingPhotos $photos, ConversationLibrary $conversations): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Resident);
        abort_if($account === null, 403);

        return Inertia::render('resident/Property', [
            'property' => Inertia::defer(function () use ($request, $directory, $photos): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Resident, $directory);
                    $property = $directory->propertiesFor($scope)[0] ?? null;

                    return $property instanceof Property
                        ? [...$property->toArray(), 'details' => $directory->detailsFor($scope, $property->id), 'photos' => $photos->for($property)]
                        : null;
                } catch (PropertywareException) {
                    return null;
                }
            })->once(as: 'resident-property-'.$account->id, until: now()->addMinutes(5)),
            'conversations' => Inertia::defer(function () use ($request, $directory, $conversations): ?array {
                try {
                    $scope = $this->clientScope($request, ClientRole::Resident, $directory);
                    $buildingId = $scope->buildingIds[0] ?? null;

                    return $buildingId === null ? null : $conversations->forBuilding($scope, $buildingId);
                } catch (PropertywareException) {
                    return null;
                }
            }),
            'documents' => Inertia::defer(function () use ($request, $directory, $documents): ?array {
                try {
                    return $documents->forClient($this->clientScope($request, ClientRole::Resident, $directory))
                        ->map(fn (Document $document): array => $document->toArray())
                        ->all();
                } catch (PropertywareException) {
                    return null;
                }
            })->once(as: 'documents-resident-'.$account->id, until: now()->addMinutes(5)),
        ]);
    }
}
