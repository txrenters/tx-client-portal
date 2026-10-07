<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ShowsConversationsWithStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ReplyMessageRequest;
use App\Http\Requests\Client\StartConversationRequest;
use App\Services\ChatHub\ClientConversations;
use App\Services\Maintenance\EarlierWorkOrderTexts;
use App\Services\Propertyware\PropertywareDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MessageController extends Controller
{
    use ShowsConversationsWithStaff;

    /**
     * The owner's conversations with TexasRenters.com support and maintenance.
     */
    public function index(Request $request, ClientConversations $conversations, PropertywareDirectory $directory, EarlierWorkOrderTexts $earlierTexts): Response
    {
        return $this->renderMessages($request, ClientRole::Owner, $conversations, $directory, $earlierTexts);
    }

    public function store(StartConversationRequest $request, ClientConversations $conversations): RedirectResponse
    {
        return $this->startConversation($request, ClientRole::Owner, $conversations);
    }

    public function reply(ReplyMessageRequest $request, int $thread, ClientConversations $conversations): RedirectResponse
    {
        return $this->replyToConversation($request, ClientRole::Owner, $thread, $conversations);
    }

    /**
     * A file attached to one of the client's earlier work order texts.
     */
    public function earlierMedia(Request $request, int $media, string $name, EarlierWorkOrderTexts $earlierTexts): SymfonyResponse
    {
        return $this->earlierTextAttachment($request, ClientRole::Owner, $media, $name, $earlierTexts);
    }
}
