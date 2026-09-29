<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HelpTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreHelpRequest;
use App\Services\Help\HelpDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Asking for help with the app itself. Belongs to the login rather than to one
 * account, since "I can't see my property" is exactly when there may be none.
 */
class HelpRequestController extends Controller
{
    /**
     * The topics to choose from and the client's requests, newest first.
     */
    public function index(Request $request, HelpDesk $desk): JsonResponse
    {
        $requests = $desk->forUser($request->user(), max(1, $request->integer('page', 1)));

        return response()->json([
            'data' => $requests->items(),
            'meta' => [
                'topics' => HelpTopic::options(),
                'currentPage' => $requests->currentPage(),
                'lastPage' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(StoreHelpRequest $request, HelpDesk $desk): JsonResponse
    {
        $helpRequest = $desk->submit($request->user(), [
            'topic' => $request->validated('topic'),
            'message' => $request->validated('message'),
            'platform' => $request->validated('platform') ?? 'app',
            'app_version' => $request->validated('app_version'),
        ], $request->userAgent());

        return response()->json(['data' => $desk->present($helpRequest)], 201);
    }
}
