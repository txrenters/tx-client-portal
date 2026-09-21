<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ChatHub\ChatHubException;
use App\Services\ChatHub\ClientConversations;
use App\Services\Propertyware\Data\ClientScope;
use App\Services\Propertyware\Data\Document;
use App\Services\Propertyware\DocumentLibrary;
use App\Services\Propertyware\MaintenanceSummary;
use App\Services\Propertyware\PropertywareException;

/**
 * The dashboard's own feeds: the client's latest messages and documents, and
 * the activity list that merges those with their work orders.
 *
 * Each returns null when its source could not be read, which the page shows as
 * an error rather than as "nothing here".
 */
trait ShowsDashboardFeeds
{
    /** How many entries each dashboard card holds. */
    private const MESSAGES = 3;

    private const DOCUMENTS = 5;

    private const ACTIVITY = 5;

    /**
     * One page of conversations, fetched at most once per request: the hub is
     * not cached, and both the messages card and the unread count read it.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $conversationPage = null;

    private bool $conversationPageFailed = false;

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function conversationPage(ClientAccount $account, ?User $reader, ClientConversations $conversations): ?array
    {
        if ($this->conversationPageFailed) {
            return null;
        }

        if ($this->conversationPage !== null) {
            return $this->conversationPage;
        }

        if (! $conversations->isAvailable()) {
            return $this->conversationPage = [];
        }

        try {
            return $this->conversationPage = $conversations->threads($account, 1, [], $reader)['threads'];
        } catch (ChatHubException) {
            $this->conversationPageFailed = true;

            return null;
        }
    }

    /**
     * How many conversations hold a reply the client has not read. Counted over
     * the same page the hub just returned, which is what the web portal's own
     * unread badge counts, so the two clients agree.
     */
    protected function unreadMessageCount(ClientAccount $account, ?User $reader, ClientConversations $conversations): ?int
    {
        if ($reader === null) {
            return 0;
        }

        $threads = $this->conversationPage($account, $reader, $conversations);

        return $threads === null
            ? null
            : count(array_filter($threads, fn (array $thread): bool => (bool) ($thread['unread'] ?? false)));
    }

    /**
     * The client's latest conversations with staff, newest first.
     *
     * @return array<int, array{id: int, subject: string, preview: string, author: ?string, unread: bool, at: ?string}>|null
     */
    protected function latestMessages(ClientAccount $account, ?User $reader, ClientConversations $conversations, int $limit = self::MESSAGES): ?array
    {
        $threads = $this->conversationPage($account, $reader, $conversations);

        if ($threads === null) {
            return null;
        }

        return collect($threads)
            ->take($limit)
            ->map(fn (array $thread): array => [
                'id' => $thread['id'],
                'subject' => $thread['subject'],
                'preview' => $thread['lastMessage']['preview'] ?? '',
                'author' => $thread['lastMessage']['author'] ?? null,
                'unread' => (bool) ($thread['unread'] ?? false),
                'at' => $thread['updatedAt'],
            ])
            ->values()
            ->all();
    }

    /**
     * The client's newest documents, or an "importing" status while a large
     * account's documents are still being read from Propertyware.
     *
     * @return array{status: string, items: array<int, array<string, mixed>>}|null
     */
    protected function latestDocuments(ClientScope $scope, DocumentLibrary $documents, int $limit = self::DOCUMENTS): ?array
    {
        try {
            if (! $documents->isReady($scope)) {
                $documents->importInBackground($scope);

                if (! $documents->isReady($scope)) {
                    return ['status' => 'importing', 'items' => []];
                }
            }

            $latest = $documents->forClient($scope)->take($limit);
        } catch (PropertywareException) {
            return null;
        }

        return [
            'status' => 'ready',
            'items' => $latest->map(fn (Document $document): array => $document->toArray())->values()->all(),
        ];
    }

    /**
     * What happened last across the client's account: work order updates, new
     * documents and new messages in one list, newest first.
     *
     * @return array<int, array{type: string, id: string, title: string, detail: ?string, at: ?string, url: string}>|null
     */
    protected function recentActivity(
        ClientRole $role,
        ClientAccount $account,
        ClientScope $scope,
        ?User $reader,
        MaintenanceSummary $maintenance,
        DocumentLibrary $documents,
        ClientConversations $conversations,
        int $limit = self::ACTIVITY,
    ): ?array {
        $summary = $maintenance->summary($scope);
        $latestDocuments = $this->latestDocuments($scope, $documents, $limit);
        $messages = $this->latestMessages($account, $reader, $conversations, $limit);

        if ($summary['status'] === 'error' && $latestDocuments === null && $messages === null) {
            return null;
        }

        $entries = collect($summary['recent'] ?? [])
            ->map(fn (array $workOrder): array => [
                'type' => 'maintenance',
                'id' => (string) $workOrder['id'],
                'title' => $workOrder['title'],
                'detail' => trim(($workOrder['statusLabel'] ?? '').($workOrder['property'] ? ' · '.$workOrder['property'] : '')),
                'at' => $workOrder['updatedAt'] ?? $workOrder['createdAt'],
                'url' => route($role->value.'.maintenance.show', $workOrder['id']),
            ])
            ->concat(collect($latestDocuments['items'] ?? [])->map(fn (array $document): array => [
                'type' => 'document',
                'id' => (string) $document['id'],
                'title' => $document['description'] ?: $document['fileName'],
                'detail' => $document['category'] ?? null,
                'at' => $document['createdAt'],
                'url' => route($role->value.'.documents'),
            ]))
            ->concat(collect($messages ?? [])->map(fn (array $message): array => [
                'type' => 'message',
                'id' => (string) $message['id'],
                'title' => $message['subject'],
                'detail' => $message['preview'] !== '' ? $message['preview'] : null,
                'at' => $message['at'],
                'url' => route($role->value.'.messages.index', ['thread' => $message['id']]),
            ]));

        return $entries
            ->sortByDesc(fn (array $entry): string => (string) $entry['at'])
            ->take($limit)
            ->values()
            ->all();
    }
}
