<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HelpTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreHelpRequest;
use App\Models\HelpRequestAttachment;
use App\Services\Help\HelpDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asking for help with the app itself. Belongs to the login rather than to one
 * account, since "I can't see my property" is exactly when there may be none.
 */
class HelpRequestController extends Controller
{
    /** Attachment links the app can fetch with its token. */
    private const ATTACHMENT_ROUTE = 'api.v1.help-requests.attachments.show';

    /**
     * The topics to choose from and the client's requests, newest first.
     */
    public function index(Request $request, HelpDesk $desk): JsonResponse
    {
        $requests = $desk->forUser($request->user(), max(1, $request->integer('page', 1)), self::ATTACHMENT_ROUTE);

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
        ], $request->userAgent(), $request->file('attachments', []));

        return response()->json(['data' => $desk->present($helpRequest, self::ATTACHMENT_ROUTE)], 201);
    }

    /**
     * A file attached to one of this login's requests; anyone else's is a 404.
     */
    public function attachment(Request $request, HelpRequestAttachment $attachment, HelpDesk $desk): StreamedResponse
    {
        abort_unless($attachment->helpRequest->user_id === $request->user()->id, 404);

        return $desk->attachmentResponse($attachment);
    }
}
