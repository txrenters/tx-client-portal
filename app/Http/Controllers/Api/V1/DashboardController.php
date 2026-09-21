<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ShowsDashboardFeeds;
use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\Data\Property;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ShowsDashboardFeeds;

    /**
     * Show the account's home screen: its properties and recent maintenance.
     *
     * @throws PropertywareException
     */
    public function __invoke(Request $request, ClientAccount $account, PropertywareDirectory $directory, MaintenanceSummary $maintenance, WorkOrderApprovals $approvals, DocumentLibrary $documents, ClientConversations $conversations): JsonResponse
    {
        $scope = $directory->scopeFor($account);
        $summary = $maintenance->summary($scope);

        if ($summary['status'] === 'error') {
            throw new PropertywareException('Maintenance requests could not be read.');
        }

        $properties = collect($directory->propertiesFor($scope));

        return response()->json(['data' => [
            'role' => $account->role->value,
            'portfolioCount' => $account->role === ClientRole::Owner ? count($scope->portfolioIds) : null,
            'propertyCount' => $properties->count(),
            'occupancy' => $account->role === ClientRole::Owner ? $directory->occupancyFor($scope) : null,
            'properties' => $properties->take(6)->map(fn (Property $property): array => $property->toArray())->values(),
            'maintenance' => [
                'counts' => $summary['counts'],
                'recent' => $summary['recent'],
            ],
            'approvals' => $account->role === ClientRole::Owner ? $approvals->summaryFor($scope, $account) : null,
            // The same feeds the portal's dashboard shows under the fold.
            'messages' => $this->latestMessages($account, $request->user(), $conversations),
            // The exact count, not what fits in the card above: the app badges it.
            'messagesUnread' => $this->unreadMessageCount($account, $request->user(), $conversations),
            'documents' => $this->latestDocuments($scope, $documents),
            'activity' => $this->recentActivity($account->role, $account, $scope, $request->user(), $maintenance, $documents, $conversations),
        ]]);
    }
}
