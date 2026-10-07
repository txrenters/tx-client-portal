<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\CreatesWorkOrders;
use App\Http\Controllers\Concerns\ShowsClientDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\CreateWorkOrderRequest;
use App\Http\Requests\Client\MaintenanceIndexRequest;
use App\Http\Requests\Client\WorkOrderApprovalRequest;
use App\Services\Maintenance\EarlierWorkOrderTexts;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\Data\WorkOrder;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceController extends Controller
{
    use CreatesWorkOrders, ShowsClientDocuments;

    /**
     * Show maintenance across the owner's portfolio, one page at a time.
     */
    public function index(MaintenanceIndexRequest $request, PropertywareDirectory $directory, MaintenanceSummary $maintenance, WorkOrderApprovals $approvals): Response
    {
        $account = $this->activeClientAccount($request, ClientRole::Owner);
        abort_if($account === null, 403);

        // The page opens on open work orders; the "All" tab asks for status=all.
        // Work orders needing approval can be open or scheduled, so that view shows both.
        $filters = $request->filters(defaultStatus: $request->validated('approval') ? null : 'open');

        return Inertia::render('owner/Maintenance', [
            'filters' => $filters,
            'requestOptions' => Inertia::defer(function () use ($request, $directory): ?array {
                try {
                    return $this->workOrderOptions($this->clientScope($request, ClientRole::Owner, $directory), $directory);
                } catch (PropertywareException) {
                    return null;
                }
            }, 'request')->once(as: 'maintenance-request-options-'.$account->id, until: now()->addMinutes(5)),
            'maintenance' => Inertia::defer(function () use ($request, $directory, $maintenance, $approvals, $account, $filters): array {
                $scope = $this->clientScope($request, ClientRole::Owner, $directory);

                try {
                    $awaitingIds = $approvals->awaitingFor($scope, $account)->pluck('id')->all();
                } catch (PropertywareException) {
                    $awaitingIds = [];
                }

                if ($filters['approval'] === 'needed') {
                    $filters['ids'] = $awaitingIds;
                }

                $page = $maintenance->paginate(
                    $scope,
                    $filters,
                    $request->page(),
                    path: $request->url(),
                );

                if (isset($page['workOrders']['data'])) {
                    $page['workOrders']['data'] = $approvals->markAwaiting($account, $page['workOrders']['data']);
                }

                return [...$page, 'awaitingApproval' => count($awaitingIds)];
            }),
        ]);
    }

    /**
     * Create a maintenance request in Propertyware.
     */
    public function store(CreateWorkOrderRequest $request, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents): RedirectResponse
    {
        return $this->storeWorkOrder($request, ClientRole::Owner, $directory, $maintenance, $documents);
    }

    /**
     * Show one maintenance request, or 404 when it isn't the client's own.
     */
    public function show(Request $request, string $workOrder, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents, WorkOrderApprovals $approvals, ConversationLibrary $conversations, EarlierWorkOrderTexts $earlierTexts): Response
    {
        $scope = $this->clientScope($request, ClientRole::Owner, $directory);

        $record = $maintenance->find($scope, $workOrder);

        abort_if($record === null, 404);

        $account = $this->activeClientAccount($request, ClientRole::Owner);
        $listed = $maintenance->forClient($scope)->first(fn (WorkOrder $candidate): bool => $candidate->id === $workOrder);

        return Inertia::render('owner/MaintenanceDetail', [
            'workOrder' => $record,
            'approval' => $listed === null ? null : $approvals->stateFor($account, $listed),
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
     * Record the owner's approve or decline decision on a work order.
     */
    public function decide(WorkOrderApprovalRequest $request, string $workOrder, PropertywareDirectory $directory, WorkOrderApprovals $approvals): RedirectResponse
    {
        $scope = $this->clientScope($request, ClientRole::Owner, $directory);

        try {
            $approval = $approvals->decide(
                $scope,
                $request->user(),
                $this->activeClientAccount($request, ClientRole::Owner),
                $workOrder,
                $request->validated('decision'),
                $request->validated('note'),
                ['ip' => $request->ip(), 'userAgent' => $request->userAgent()],
            );
        } catch (PropertywareException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We could not record your decision right now. Please try again.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $approval->decision === 'approved'
            ? __('Work order approved. Thank you.')
            : __('Got it. We have let your team know you declined.')]);

        return back();
    }

    /**
     * Download a file attached to this work order.
     */
    public function downloadDocument(Request $request, string $workOrder, string $document, PropertywareDirectory $directory, DocumentLibrary $documents, MaintenanceSummary $maintenance): StreamedResponse
    {
        return $this->streamWorkOrderDocument($request, ClientRole::Owner, $workOrder, $document, $directory, $documents, $maintenance);
    }

    /**
     * Show a file attached to this work order in the browser.
     */
    public function previewDocument(Request $request, string $workOrder, string $document, PropertywareDirectory $directory, DocumentLibrary $documents, MaintenanceSummary $maintenance): StreamedResponse
    {
        return $this->streamWorkOrderDocument($request, ClientRole::Owner, $workOrder, $document, $directory, $documents, $maintenance, inline: true);
    }
}
