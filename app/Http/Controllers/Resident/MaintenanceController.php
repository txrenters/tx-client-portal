<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\CreatesWorkOrders;
use App\Http\Controllers\Concerns\ShowsClientDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\CreateWorkOrderRequest;
use App\Http\Requests\Client\MaintenanceIndexRequest;
use App\Services\Maintenance\EarlierWorkOrderTexts;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceController extends Controller
{
    use CreatesWorkOrders, ShowsClientDocuments;

    /**
     * Show the tenant's maintenance requests, one page at a time.
     */
    public function index(MaintenanceIndexRequest $request, PropertywareDirectory $directory, MaintenanceSummary $maintenance): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Resident);
        abort_if($account === null, 403);

        // The page opens on open work orders; the "All" tab asks for status=all.
        $filters = $request->filters(defaultStatus: 'open');

        return Inertia::render('resident/Maintenance', [
            'filters' => $filters,
            'requestOptions' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    return $this->workOrderOptions($this->clientScope($request, ClientRole::Resident, $directory), $directory);
                } catch (PropertywareException) {
                    return null;
                }
            }, 'request')->once(as: 'maintenance-request-options-'.$account->id, until: now()->addMinutes(5)),
            'maintenance' => Inertia::defer(fn (): array => $maintenance->paginate(
                $this->clientScope($request, ClientRole::Resident, $directory),
                $filters,
                $request->page(),
                path: $request->url(),
            )),
        ]);
    }

    /**
     * Create a maintenance request in Propertyware.
     */
    public function store(CreateWorkOrderRequest $request, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents): RedirectResponse
    {
        return $this->storeWorkOrder($request, ClientRole::Resident, $directory, $maintenance, $documents);
    }

    /**
     * Show one maintenance request, or 404 when it isn't the client's own.
     */
    public function show(Request $request, string $workOrder, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents, ConversationLibrary $conversations, EarlierWorkOrderTexts $earlierTexts): Response
    {
        $scope = $this->clientScope($request, ClientRole::Resident, $directory);

        $record = $maintenance->find($scope, $workOrder);

        abort_if($record === null, 404);

        $account = $this->activeClientAccount($request, ClientRole::Resident);

        return Inertia::render('resident/MaintenanceDetail', [
            'workOrder' => $record,
            // Texts about this work order from before the messaging hub.
            'earlierTexts' => Inertia::defer(fn (): ?array => $account === null ? null : $earlierTexts->forWorkOrder($account, $workOrder)),
            'conversations' => Inertia::defer(function () use ($scope, $record, $conversations): ?array {
                try {
                    return $conversations->forWorkOrder($scope, $record['buildingId'], $record['number']);
                } catch (PropertywareException) {
                    return null;
                }
            }),
            'documents' => Inertia::defer(function () use ($scope, $workOrder, $documents): ?array {
                try {
                    return $documents->forWorkOrder($scope, $workOrder)
                        ->map(fn (Document $document): array => $document->toArray())
                        ->all();
                } catch (PropertywareException) {
                    return null;
                }
            }),
        ]);
    }

    /**
     * Download a file attached to this work order.
     */
    public function downloadDocument(Request $request, string $workOrder, string $document, PropertywareDirectory $directory, DocumentLibrary $documents, MaintenanceSummary $maintenance): StreamedResponse
    {
        return $this->streamWorkOrderDocument($request, ClientRole::Resident, $workOrder, $document, $directory, $documents, $maintenance);
    }

    /**
     * Show a file attached to this work order in the browser.
     */
    public function previewDocument(Request $request, string $workOrder, string $document, PropertywareDirectory $directory, DocumentLibrary $documents, MaintenanceSummary $maintenance): StreamedResponse
    {
        return $this->streamWorkOrderDocument($request, ClientRole::Resident, $workOrder, $document, $directory, $documents, $maintenance, inline: true);
    }
}
