<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use App\Services\Propertyware\WorkOrderApprovals;
use Illuminate\Http\JsonResponse;

class ApprovalController extends Controller
{
    /**
     * List the work orders waiting for the owner's approval. Residents always get an empty list.
     *
     * @throws PropertywareException
     */
    public function __invoke(ClientAccount $account, PropertywareDirectory $directory, WorkOrderApprovals $approvals): JsonResponse
    {
        $summary = $approvals->summaryFor($directory->scopeFor($account), $account, limit: 50);

        return response()->json([
            'data' => $summary['items'],
            'meta' => ['count' => $summary['count']],
        ]);
    }
}
