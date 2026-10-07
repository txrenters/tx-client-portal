<?php

namespace App\Services\Maintenance;

use App\Enums\ClientRole;
use App\Models\ClientAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * The texts a client exchanged with our maintenance team about their work
 * orders before the messaging hub took over, read from tx-maintenance and
 * shown as closed conversations.
 *
 * tx-maintenance decides what belongs to the client: only their own work
 * orders, their side of them, and texts to or from their own numbers.
 *
 * The Messages list holds only the client's own texts; a work order's page
 * holds everyone's on the client's side of it (forWorkOrder).
 *
 * Each work order's texts are one conversation, shaped like a hub thread but
 * with a negative id (minus tx-maintenance's work order id), so it can never
 * be mistaken for one.
 */
class EarlierWorkOrderTexts
{
    /** What the client sees as the author of our texts. */
    public const STAFF = 'Maintenance team';

    /** Attachment links for the website, through the signed-in session. */
    public const WEB = 'web';

    /** Attachment links for the app: API paths it opens with its token. */
    public const API = 'api';

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
    public function conversations(ClientAccount $account, string $surface = self::WEB): ?array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        $key = 'maintenance-texts:'.$account->id;
        $conversations = Cache::get($key);

        if (! is_array($conversations)) {
            $data = $this->fetch($account);

            if ($data === null) {
                return null;
            }

            $conversations = collect($data)
                ->map(fn (array $item): array => $this->present($item, $account))
                ->sortByDesc(fn (array $conversation): string => (string) $conversation['thread']['updatedAt'])
                ->values()
                ->all();

            Cache::put($key, $conversations, now()->addMinutes(10));
        }

        return array_map(fn (array $conversation): array => $this->linkAttachments($conversation, $account, $surface), $conversations);
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
    public function conversation(ClientAccount $account, int $threadId, string $surface = self::WEB): ?array
    {
        return collect($this->conversations($account, $surface) ?? [])
            ->first(fn (array $conversation): bool => $conversation['thread']['id'] === $threadId);
    }

    /**
     * Everyone's earlier texts on the client's side of one Propertyware work
     * order: the household on its lease, or the property's owners. Null when
     * there were none or tx-maintenance can't be reached.
     *
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}|null
     */
    public function forWorkOrder(ClientAccount $account, string $workOrderId, string $surface = self::WEB): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $key = 'maintenance-texts:'.$account->id.':work-order:'.$workOrderId;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            $conversation = $cached['conversation'];
        } else {
            $data = $this->fetch($account, $workOrderId);

            if ($data === null) {
                return null;
            }

            $conversation = isset($data[0]) ? $this->present($data[0], $account) : null;
            Cache::put($key, ['conversation' => $conversation], now()->addMinutes(10));
        }

        return $conversation === null ? null : $this->linkAttachments($conversation, $account, $surface);
    }

    /**
     * Stream one attachment of the client's earlier texts from tx-maintenance,
     * which only hands over a file on a text the client may see. Null when it
     * won't, or can't be reached.
     */
    public function attachment(ClientAccount $account, int $mediaId, string $fileName): ?Response
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $response = $this->client()->get('/api/portal/v1/message-media/'.$mediaId, [
                'contact' => $account->propertyware_id,
                'party' => $this->party($account),
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Could not reach tx-maintenance for an earlier text attachment.', ['message' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $fileName, Str::ascii($fileName) ?: 'attachment'),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Point each message's attachments at the portal, never at tx-maintenance.
     *
     * @param  array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}  $conversation
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    private function linkAttachments(array $conversation, ClientAccount $account, string $surface): array
    {
        $conversation['messages'] = array_map(function (array $message) use ($account, $surface): array {
            $message['mediaUrls'] = array_map(fn (array $media): string => $surface === self::API
                ? 'accounts/'.$account->id.'/messages/earlier-media/'.$media['id'].'/'.rawurlencode((string) $media['name'])
                : route($account->role->value.'.messages.earlier-media', ['media' => $media['id'], 'name' => $media['name']]),
                $message['attachments'] ?? []);
            unset($message['attachments']);

            return $message;
        }, $conversation['messages']);

        return $conversation;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function fetch(ClientAccount $account, ?string $workOrderId = null): ?array
    {
        try {
            $response = $this->client()->get('/api/portal/v1/message-history', array_filter([
                'contact' => $account->propertyware_id,
                'party' => $this->party($account),
                'work_order' => $workOrderId,
            ]));
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

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.tx_maintenance.url'), '/'))
            ->withToken((string) config('services.tx_maintenance.token'))
            ->withOptions(['verify' => config('services.tx_maintenance.verify', true)])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(20);
    }

    private function party(ClientAccount $account): string
    {
        return $account->role === ClientRole::Owner ? 'owner' : 'tenant';
    }

    /**
     * @param  array{work_order: array<string, mixed>, messages: array<int, array<string, mixed>>}  $item
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}
     */
    private function present(array $item, ClientAccount $account): array
    {
        $workOrder = $item['work_order'];
        $someoneElse = $account->role === ClientRole::Owner ? 'another owner' : 'another resident';
        $messages = array_map(fn (array $message): array => [
            'id' => (int) $message['id'],
            'body' => (string) ($message['body'] ?? ''),
            // Linked for the website or the app when read (linkAttachments).
            'mediaUrls' => [],
            'attachments' => array_values(array_filter(
                (array) ($message['media'] ?? []),
                fn (mixed $media): bool => is_array($media) && isset($media['id'], $media['name']),
            )),
            'number' => null,
            'fromClient' => ($message['from'] ?? null) === 'client',
            'author' => match ($message['from'] ?? null) {
                'client' => null,
                'other' => ucfirst($someoneElse),
                default => ($message['to_you'] ?? true) ? self::STAFF : self::STAFF.', to '.$someoneElse,
            },
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
                    'hasAttachment' => $latest['attachments'] !== [],
                ],
                'unread' => false,
                'category' => 'maintenance',
            ],
            'messages' => $messages,
        ];
    }
}
