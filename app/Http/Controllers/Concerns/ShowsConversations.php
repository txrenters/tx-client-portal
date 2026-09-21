<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientRole;
use App\Http\Requests\Client\ConversationIndexRequest;
use App\Services\Propertyware\ConversationLibrary;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lists the conversations TexasRenters.com keeps with a client.
 */
trait ShowsConversations
{
    use ResolvesClientScope;

    /**
     * Render the client's Propertyware conversations page.
     */
    protected function renderConversations(ConversationIndexRequest $request, ClientRole $role, PropertywareDirectory $directory, ConversationLibrary $conversations): Response
    {
        abort_if($this->activeClientAccount($request, $role) === null, 403);

        $filters = $request->filters();

        return Inertia::render('client/Conversations', [
            'portal' => $role->value,
            'filters' => $filters,
            'conversations' => Inertia::defer(function () use ($request, $role, $directory, $conversations, $filters): ?array {
                try {
                    return $conversations->paginate($this->clientScope($request, $role, $directory), $filters, $request->page(), path: $request->url());
                } catch (PropertywareException) {
                    return null;
                }
            }),
        ]);
    }
}
