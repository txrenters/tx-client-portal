<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientRole;
use App\Http\Requests\Client\ReplyMessageRequest;
use App\Http\Requests\Client\StartConversationRequest;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use App\Services\Maintenance\EarlierWorkOrderTexts;
use App\Services\Propertyware\PropertywareDirectory;
use App\Services\Propertyware\PropertywareException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The Messages page: the client's conversations with TexasRenters.com support
 * and maintenance, held in the messaging hub (tx-chatbot), and below them the
 * work order texts from before the hub, as closed conversations with negative
 * ids (EarlierWorkOrderTexts).
 */
trait ShowsConversationsWithStaff
{
    use ResolvesClientScope;

    protected function renderMessages(Request $request, ClientRole $role, ClientConversations $conversations, PropertywareDirectory $directory, EarlierWorkOrderTexts $earlierTexts): Response
    {
        $account = $this->activeClientAccount($request, $role);
        abort_if($account === null, 403);

        $validated = $request->validate([
            // Negative ids are earlier work order texts.
            'thread' => ['nullable', 'integer', 'not_in:0'],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:open,closed'],
            // Which date preset the page shows; the dates do the filtering.
            'range' => ['nullable', 'in:7,30,90,365,year,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $filters = [
            'search' => $validated['search'] ?? null,
            'status' => $validated['status'] ?? null,
            'range' => $validated['range'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
        $selected = isset($validated['thread']) ? (int) $validated['thread'] : null;

        return Inertia::render('client/Messages', [
            'portal' => $role->value,
            'available' => $conversations->isAvailable(),
            'contactId' => $account->propertyware_id,
            'selectedThreadId' => $selected,
            'filters' => $filters,
            // Which property a new message is about. Owners with several are asked;
            // tenants have the one home their scope names, so they are never asked.
            'properties' => $role !== ClientRole::Owner ? [] : Inertia::defer(function () use ($request, $role, $directory): ?array {
                try {
                    return $this->propertyOptions($this->clientScope($request, $role, $directory), $directory);
                } catch (PropertywareException) {
                    return null;
                }
            })->once(as: 'message-property-options-'.$account->id, until: now()->addMinutes(5)),
            // Work order texts from before the hub, listed after its conversations.
            'earlierTexts' => Inertia::defer(fn (): ?array => $earlierTexts->threads($account), 'earlierTexts'),
            // Loaded after the page, then page by page as the client scrolls the list.
            'threads' => Inertia::scroll(function () use ($request, $conversations, $account, $validated, $filters): LengthAwarePaginator {
                $page = (int) ($validated['page'] ?? 1);

                try {
                    $result = $conversations->threads($account, $page, $filters, $request->user());
                } catch (ChatHubException) {
                    $result = ['threads' => [], 'total' => 0];
                }

                // The page the hub actually reached, which is further on when
                // pages of empty conversations were skipped.
                return new LengthAwarePaginator($result['threads'], $result['total'], 25, $result['currentPage'] ?? $page, [
                    'path' => $request->url(),
                    'query' => array_filter($filters),
                ]);
            })->defer(),
            'conversation' => $selected === null ? null : Inertia::defer(function () use ($request, $conversations, $earlierTexts, $account, $selected): ?array {
                if ($selected < 0) {
                    return $earlierTexts->conversation($account, $selected) ?? abort(404);
                }

                try {
                    $conversation = $conversations->conversation($account, $selected) ?? abort(404);
                    $conversations->markRead($request->user(), $conversation);

                    return $conversation;
                } catch (ChatHubException) {
                    return null;
                }
            }, 'conversation'),
        ]);
    }

    /**
     * A file on one of the client's earlier work order texts, passed on from
     * tx-maintenance, which checks the client may see the text it came with.
     */
    protected function earlierTextAttachment(Request $request, ClientRole $role, int $media, string $name, EarlierWorkOrderTexts $earlierTexts): SymfonyResponse
    {
        $account = $this->activeClientAccount($request, $role);
        abort_if($account === null, 403);

        return $earlierTexts->attachment($account, $media, $name) ?? abort(404);
    }

    protected function startConversation(StartConversationRequest $request, ClientRole $role, ClientConversations $conversations): RedirectResponse
    {
        $account = $this->activeClientAccount($request, $role);
        abort_if($account === null, 403);

        try {
            $thread = $conversations->start(
                $account,
                $request->validated('subject'),
                $request->validated('body'),
                $request->file('attachments', []),
                $request->validated('category'),
                $request->propertyId(),
            );
        } catch (ChatHubException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We could not send your message right now. Please try again.')]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Message sent. Our team will reply here and by text.')]);

        return to_route($role->value.'.messages.index', ['thread' => $thread['id']]);
    }

    protected function replyToConversation(ReplyMessageRequest $request, ClientRole $role, int $thread, ClientConversations $conversations): RedirectResponse
    {
        $account = $this->activeClientAccount($request, $role);
        abort_if($account === null, 403);

        try {
            abort_unless($conversations->reply($account, $thread, $request->validated('body'), $request->idempotencyKey(), $request->file('attachments', [])), 404);
        } catch (ChatHubException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('We could not send your message right now. Please try again.')]);
        }

        return back();
    }
}
