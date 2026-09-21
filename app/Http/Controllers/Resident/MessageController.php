<?php

namespace App\Http\Controllers\Resident;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ShowsConversationsWithStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ReplyMessageRequest;
use App\Http\Requests\Client\StartConversationRequest;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class MessageController extends Controller
{
    use ShowsConversationsWithStaff;

    /**
     * The resident's conversations with TexasRenters.com support and maintenance.
     */
    public function index(Request $request, ClientConversations $conversations, PropertywareDirectory $directory, ConversationLibrary $library): Response
    {
        return $this->renderMessages($request, ClientRole::Resident, $conversations, $directory, $library);
    }

    public function store(StartConversationRequest $request, ClientConversations $conversations): RedirectResponse
    {
        return $this->startConversation($request, ClientRole::Resident, $conversations);
    }

    public function reply(ReplyMessageRequest $request, int $thread, ClientConversations $conversations): RedirectResponse
    {
        return $this->replyToConversation($request, ClientRole::Resident, $thread, $conversations);
    }
}
