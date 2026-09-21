<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ReplyMessageRequest;
use App\Http\Requests\Client\StartConversationRequest;
use App\Models\ClientAccount;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The account's conversations with TexasRenters.com support and maintenance.
 *
 * For live updates the app listens on the private channel
 * "contacts.{contactId}.messages" (event "conversation.updated"), authorized
 * at /api/v1/broadcasting/auth with its token.
 */
class MessageController extends Controller
{
    /**
     * @throws ChatHubException
     */
    public function index(Request $request, ClientAccount $account, ClientConversations $conversations): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:open,closed'],
            // Only the three a client can start a thread under; `review` is ours.
            'category' => ['nullable', 'in:leasing,accounting,maintenance'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $result = $conversations->threads($account, (int) ($validated['page'] ?? 1), $validated, $request->user());

        return response()->json([
            'data' => $result['threads'],
            'meta' => [
                'currentPage' => $result['currentPage'],
                'lastPage' => $result['lastPage'],
                'total' => $result['total'],
                'channel' => "contacts.{$account->propertyware_id}.messages",
            ],
        ]);
    }

    /**
     * @throws ChatHubException
     */
    public function show(Request $request, ClientAccount $account, int $thread, ClientConversations $conversations): JsonResponse
    {
        $conversation = $conversations->conversation($account, $thread);

        abort_if($conversation === null, 404);

        $conversations->markRead($request->user(), $conversation);

        return response()->json(['data' => $conversation]);
    }

    /**
     * @throws ChatHubException
     */
    public function store(StartConversationRequest $request, ClientAccount $account, ClientConversations $conversations): JsonResponse
    {
        $thread = $conversations->start(
            $account,
            $request->validated('subject'),
            $request->validated('body'),
            $request->file('attachments', []),
            $request->validated('category'),
            $request->propertyId(),
        );

        return response()->json(['data' => $thread], 201);
    }

    /**
     * @throws ChatHubException
     */
    public function reply(ReplyMessageRequest $request, ClientAccount $account, int $thread, ClientConversations $conversations): JsonResponse
    {
        abort_unless($conversations->reply($account, $thread, $request->validated('body'), $request->idempotencyKey(), $request->file('attachments', [])), 404);

        return response()->json(['data' => $conversations->conversation($account, $thread)], 201);
    }
}
