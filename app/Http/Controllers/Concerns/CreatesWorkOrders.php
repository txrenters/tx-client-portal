<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientRole;
use App\Http\Requests\Client\CreateWorkOrderRequest;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;

/**
 * Lets owners and tenants create work orders from the portal.
 *
 * Used alongside ShowsClientDocuments, which provides clientScope().
 */
trait CreatesWorkOrders
{
    /**
     * What the new-request form offers this client.
     *
     * @return array{categories: array<int, array{value: string, label: string}>, properties: array<int, array{id: int, label: string}>, maxPhotos: int, emergencyPhone: ?string}
     *
     * @throws PropertywareException
     */
    protected function workOrderOptions(ClientScope $scope, PropertywareDirectory $directory): array
    {
        return [
            'categories' => collect(MaintenanceSummary::clientCategories())
                ->map(fn (string $category): array => ['value' => $category, 'label' => $category])
                ->all(),
            'properties' => $this->propertyOptions($scope, $directory),
            'maxPhotos' => (int) config('maintenance.max_photos'),
            'emergencyPhone' => config('maintenance.emergency_phone'),
        ];
    }

    /**
     * Create the work order, attach any photos, and open it.
     */
    protected function storeWorkOrder(CreateWorkOrderRequest $request, ClientRole $role, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents): RedirectResponse
    {
        $scope = $this->clientScope($request, $role, $directory);

        try {
            $created = $maintenance->create($scope, $request->validated());
        } catch (PropertywareException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We could not send your request right now. Please try again.')]);

            return back();
        }

        $failedPhotos = $this->attachPhotos($scope, $documents, (int) $created['id'], $request->file('photos', []));

        Inertia::flash('toast', $failedPhotos === 0
            ? ['type' => 'success', 'message' => __('Your request was sent.')]
            : ['type' => 'warning', 'message' => __('Your request was sent, but :count photo(s) could not be attached.', ['count' => $failedPhotos])]);

        return to_route($role->value.'.maintenance.show', $created['id']);
    }

    /**
     * Upload the request's photos to the new work order, counting failures.
     *
     * @param  array<int, UploadedFile>  $photos
     */
    protected function attachPhotos(ClientScope $scope, DocumentLibrary $documents, int $workOrderId, array $photos): int
    {
        $failed = 0;

        foreach ($photos as $photo) {
            try {
                $documents->upload($scope, $photo, null, 'work_order', $workOrderId);
            } catch (PropertywareException) {
                $failed++;
            }
        }

        return $failed;
    }
}
