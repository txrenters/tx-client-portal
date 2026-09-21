<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ConversationIndexRequest;
use App\Models\ClientAccount;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\JsonResponse;

class ConversationController extends Controller
{
    /**
     * List one page of the account's conversations, most recent first. Filter
     * with property={buildingId} for one property, or property=account.
     *
     * @throws PropertywareException
     */
    public function __invoke(ConversationIndexRequest $request, ClientAccount $account, PropertywareDirectory $directory, ConversationLibrary $conversations): JsonResponse
    {
        $result = $conversations->paginate($directory->scopeFor($account), $request->filters(), $request->page(), path: $request->url());
        $page = $result['results'];

        return response()->json([
            'data' => $page['data'],
            'meta' => [
                'currentPage' => $page['current_page'],
                'lastPage' => $page['last_page'],
                'perPage' => $page['per_page'],
                'total' => $page['total'],
                'unfilteredTotal' => $result['total'],
                'properties' => $result['properties'],
            ],
        ]);
    }
}
