<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\CreatesWorkOrders;
use App\Http\Controllers\Concerns\ShowsClientDocuments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\CreateWorkOrderRequest;
use App\Http\Requests\Client\MaintenanceIndexRequest;
use App\Http\Requests\Client\WorkOrderApprovalRequest;
use App\Models\ClientAccount;
use App\Services\Maintenance\EarlierWorkOrderTexts;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\Data\WorkOrder;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceController extends Controller
{
    use CreatesWorkOrders, ShowsClientDocuments;

    /**
     * List one page of the account's work orders, newest first.
     *
     * @throws PropertywareException
     */
    public function index(MaintenanceIndexRequest $request, ClientAccount $account, PropertywareDirectory $directory, MaintenanceSummary $maintenance, WorkOrderApprovals $approvals): JsonResponse
    {
        $scope = $directory->scopeFor($account);
        $filters = $request->filters();

        if ($filters['approval'] === 'needed') {
            $filters['ids'] = $approvals->awaitingFor($scope, $account)->pluck('id')->all();
        }

        $result = $maintenance->paginate($scope, $filters, $request->page(), path: $request->url());

        if ($result['status'] === 'error') {
            throw new PropertywareException('Maintenance requests could not be read.');
        }

        $page = $result['workOrders'];

        return response()->json([
            'data' => $approvals->markAwaiting($account, $page['data']),
            'meta' => [
                'currentPage' => $page['current_page'],
                'lastPage' => $page['last_page'],
                'perPage' => $page['per_page'],
                'total' => $page['total'],
                'unfilteredTotal' => $result['total'],
                'counts' => $result['counts'],
                'properties' => $result['properties'],
            ],
        ]);
    }

    /**
     * What the app's new-request form should offer: categories, properties
     * (owners), the photo limit and the emergency phone number.
     *
     * @throws PropertywareException
     */
    public function requestOptions(ClientAccount $account, PropertywareDirectory $directory): JsonResponse
    {
        return response()->json(['data' => $this->workOrderOptions($directory->scopeFor($account), $directory)]);
    }

    /**
     * Create a work order from the app, attach its photos, and return it.
     *
     * @throws PropertywareException
     */
    public function store(CreateWorkOrderRequest $request, ClientAccount $account, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents): JsonResponse
    {
        $scope = $directory->scopeFor($account);
        $created = $maintenance->create($scope, $request->workOrder());
        $failedPhotos = $this->attachPhotos($scope, $documents, (int) $created['id'], $request->file('photos', []));

        return response()->json([
            'data' => $maintenance->find($scope, (string) $created['id']),
            'meta' => ['photosNotAttached' => $failedPhotos],
        ], 201);
    }

    /**
     * Show one work order with its timeline, or 404 when it isn't the account's.
     *
     * @throws PropertywareException
     */
    public function show(ClientAccount $account, string $workOrder, PropertywareDirectory $directory, MaintenanceSummary $maintenance, WorkOrderApprovals $approvals): JsonResponse
    {
        $scope = $directory->scopeFor($account);
        $record = $this->findWorkOrder($scope, $workOrder, $maintenance);
        $listed = $maintenance->forClient($scope)->first(fn (WorkOrder $candidate): bool => $candidate->id === $workOrder);

        return response()->json([
            'data' => [...$record, 'approval' => $listed === null ? null : $approvals->stateFor($account, $listed)],
        ]);
    }

    /**
     * Record the owner's approve or decline decision from the app.
     *
     * @throws PropertywareException
     */
    public function decide(WorkOrderApprovalRequest $request, ClientAccount $account, string $workOrder, PropertywareDirectory $directory, WorkOrderApprovals $approvals, MaintenanceSummary $maintenance): JsonResponse
    {
        $scope = $directory->scopeFor($account);

        $approvals->decide(
            $scope,
            $request->user(),
            $account,
            $workOrder,
            $request->validated('decision'),
            $request->validated('note'),
            ['ip' => $request->ip(), 'userAgent' => $request->userAgent()],
        );

        $listed = $maintenance->forClient($scope)->first(fn (WorkOrder $candidate): bool => $candidate->id === $workOrder);

        return response()->json(['data' => $approvals->stateFor($account, $listed)]);
    }

    /**
     * List the files attached to one of the account's work orders.
     *
     * @throws PropertywareException
     */
    public function documents(ClientAccount $account, string $workOrder, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents): JsonResponse
    {
        $scope = $directory->scopeFor($account);

        $this->findWorkOrder($scope, $workOrder, $maintenance);

        return response()->json([
            'data' => $documents->forWorkOrder($scope, $workOrder)
                ->map(fn (Document $document): array => $document->toArray())
                ->values(),
        ]);
    }

    /**
     * List the conversations on the work order's property that mention it.
     *
     * @throws PropertywareException
     */
    public function conversations(ClientAccount $account, string $workOrder, PropertywareDirectory $directory, MaintenanceSummary $maintenance, ConversationLibrary $conversations): JsonResponse
    {
        $scope = $directory->scopeFor($account);
        $record = $this->findWorkOrder($scope, $workOrder, $maintenance);

        return response()->json([
            'data' => $conversations->forWorkOrder($scope, $record['buildingId'], $record['number']),
        ]);
    }

    /**
     * The texts about the work order from before the messaging hub, from
     * everyone on the account's side of it, as one closed conversation; null
     * when there were none.
     *
     * @throws PropertywareException
     */
    public function texts(ClientAccount $account, string $workOrder, PropertywareDirectory $directory, MaintenanceSummary $maintenance, EarlierWorkOrderTexts $earlierTexts): JsonResponse
    {
        $this->findWorkOrder($directory->scopeFor($account), $workOrder, $maintenance);

        return response()->json(['data' => $earlierTexts->forWorkOrder($account, $workOrder)]);
    }

    /**
     * Download a file attached to one of the account's work orders.
     *
     * @throws PropertywareException
     */
    public function downloadDocument(Request $request, ClientAccount $account, string $workOrder, string $document, PropertywareDirectory $directory, MaintenanceSummary $maintenance, DocumentLibrary $documents): StreamedResponse
    {
        $scope = $directory->scopeFor($account);

        $this->findWorkOrder($scope, $workOrder, $maintenance);

        $file = $documents->findForWorkOrder($scope, $workOrder, $document);

        abort_if($file === null, 404);

        return $this->streamFile($documents, $file, inline: $request->boolean('inline'));
    }

    /**
     * Find a work order the account may open, or answer 404.
     *
     * Propertyware returns any work order by id, so access has to be proven
     * before the record or its attachments are read.
     *
     * @return array<string, mixed>
     *
     * @throws PropertywareException
     */
    private function findWorkOrder(ClientScope $scope, string $workOrder, MaintenanceSummary $maintenance): array
    {
        $record = $maintenance->find($scope, $workOrder);

        abort_if($record === null, 404);

        return $record;
    }
}
