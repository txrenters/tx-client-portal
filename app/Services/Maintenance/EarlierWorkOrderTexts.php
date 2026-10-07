<?php

namespace App\Services\Maintenance;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The texts a client exchanged with our maintenance team about their work
 * orders before the messaging hub took over, read from tx-maintenance and
 * shown as closed conversations.
 *
 * tx-maintenance decides what belongs to the client: only their own work
 * orders, their side of them, and texts to or from their own numbers.
 *
 * Each work order's texts are one conversation, shaped like a hub thread but
 * with a negative id (minus tx-maintenance's work order id), so it can never
 * be mistaken for one.
 */
class EarlierWorkOrderTexts
{
    /** What the client sees as the author of our texts. */
    public const STAFF = 'Maintenance team';

    public function isAvailable(): bool
    {
        return filled(config('services.tx_maintenance.url')) && filled(config('services.tx_maintenance.token'));
    }

    /**
     * The client's earlier work order texts, newest conversation first. Empty
     * when tx-maintenance isn't set up; null when it can't be reached.
     *
     * @return array<int, array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}>|null
     */
    public function conversations(ClientAccount $account): ?array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $key = 'maintenance-texts:'.$account->id;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $data = $this->fetch($account);

        if ($data === null) {
            return null;
        }

        $conversations = collect($data)
            ->map(fn (array $item): array => $this->present($item))
            ->sortByDesc(fn (array $conversation): string => (string) $conversation['thread']['updatedAt'])
            ->values()
            ->all();

        Cache::put($key, $conversations, now()->addMinutes(10));

        return $conversations;
    }

    /**
     * The conversation list rows, without their messages.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function threads(ClientAccount $account): ?array
    {
        $conversations = $this->conversations($account);

        return $conversations === null ? null : array_map(fn (array $conversation): array => $conversation['thread'], $conversations);
    }

    /**
     * One of the client's earlier conversations, by its (negative) id.
     *
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}|null
     */
    public function conversation(ClientAccount $account, int $threadId): ?array
    {
        return collect($this->conversations($account) ?? [])
            ->first(fn (array $conversation): bool => $conversation['thread']['id'] === $threadId);
    }

    /**
     * The earlier texts about one Propertyware work order, if there were any.
     *
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}|null
     */
    public function forWorkOrder(ClientAccount $account, string $workOrderId): ?array
    {
        return collect($this->conversations($account) ?? [])
            ->first(fn (array $conversation): bool => $conversation['thread']['workOrderId'] === $workOrderId);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetch(ClientAccount $account): ?array
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('services.tx_maintenance.url'), '/'))
                ->withToken((string) config('services.tx_maintenance.token'))
                ->withOptions(['verify' => config('services.tx_maintenance.verify', true)])
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->get('/api/portal/v1/message-history', [
                    'contact' => $account->propertyware_id,
                    'party' => $account->role === ClientRole::Owner ? 'owner' : 'tenant',
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach tx-maintenance for earlier work order texts.', ['message' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            Log::warning('tx-maintenance refused an earlier work order texts lookup.', ['status' => $response->status()]);

            return null;
        }

        return $response->json('data');
    }

    /**
     * @param  array{work_order: array<string, mixed>, messages: array<int, array<string, mixed>>}  $item
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    private function present(array $item): array
    {
        $workOrder = $item['work_order'];
        $messages = array_map(fn (array $message): array => [
            'id' => (int) $message['id'],
            'body' => (string) ($message['body'] ?? ''),
            'mediaUrls' => array_values(array_filter((array) ($message['media'] ?? []), 'is_string')),
            'number' => null,
            'fromClient' => ($message['from'] ?? null) === 'client',
            'author' => ($message['from'] ?? null) === 'client' ? null : self::STAFF,
            'authorPhotoUrl' => null,
            'at' => $message['sent_at'] ?? null,
        ], $item['messages']);
        $latest = end($messages) ?: null;

        return [
            'thread' => [
                'id' => -(int) $workOrder['id'],
                'subject' => match (true) {
                    filled($workOrder['title'] ?? null) => (string) $workOrder['title'],
                    filled($workOrder['number'] ?? null) => 'Work order #'.$workOrder['number'],
                    default => 'Work order texts',
                },
                'kind' => 'maintenance',
                'workOrderId' => filled($workOrder['propertyware_id'] ?? null) ? (string) $workOrder['propertyware_id'] : null,
                'workOrderNumber' => filled($workOrder['number'] ?? null) ? (string) $workOrder['number'] : null,
                'isClosed' => true,
                'isHistory' => true,
                'assignedStaff' => null,
                'updatedAt' => $latest['at'] ?? null,
                'lastMessage' => $latest === null ? null : [
                    'id' => $latest['id'],
                    'author' => $latest['author'],
                    'preview' => $latest['body'],
                    'fromClient' => $latest['fromClient'],
                    'hasAttachment' => $latest['mediaUrls'] !== [],
                ],
                'unread' => false,
                'category' => 'maintenance',
            ],
            'messages' => $messages,
        ];
    }
}
