<?php

namespace App\Http\Controllers\Owner;

use App\Enums\ClientRole;
use App\Http\Controllers\Concerns\ShowsConversations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ConversationIndexRequest;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\PropertywareDirectory;
use Inertia\Response;

class ConversationController extends Controller
{
    use ShowsConversations;

    /**
     * List the owner's conversations with TexasRenters.com.
     */
    public function index(ConversationIndexRequest $request, PropertywareDirectory $directory, ConversationLibrary $conversations): Response
    {
        return $this->renderConversations($request, ClientRole::Owner, $directory, $conversations);
    }
}
