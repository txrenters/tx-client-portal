<?php

namespace App\Services\ChatHub;

use App\Events\ClientConversationUpdated;
use App\Models\ClientAccount;
use App\Models\ConversationRead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A client's conversations with TexasRenters.com, as the portal shows them.
 *
 * Every thread is checked against the client's own Propertyware contact, so a
 * client can never open or write in someone else's conversation by guessing
 * an id. Phone numbers and internal fields never leave this class.
 */
class ClientConversations
{
    /** Further pages read when one holds only conversations without messages. */
    private const EMPTY_PAGES_READ_AHEAD = 10;

    public function __construct(private ChatHubClient $hub) {}

    public function isAvailable(): bool
    {
        return $this->hub->isConfigured();
    }

    /**
     * @param  array{search?: ?string, status?: ?string, category?: ?string, from?: ?string, to?: ?string}  $filters
     * @return array{threads: array<int, array<string, mixed>>, currentPage: int, lastPage: int, total: int}
     *
     * @throws ChatHubException
     */
    public function threads(ClientAccount $account, int $page = 1, array $filters = [], ?User $reader = null): array
    {
        // A session the support app opened without a single message in it (a
        // number shared with the office can pile up hundreds) says nothing, so
        // it is left out. A page of nothing but those would stop the list
        // there, so the next pages are read until one has something to show;
        // currentPage says how far that went, for the list to carry on from.
        $hubPage = $page;
        $result = $this->hub->threads($account->propertyware_id, $hubPage, $filters);
        $withMessages = $this->withMessages($result);

        while ($withMessages === [] && $hubPage < (int) ($result['meta']['last_page'] ?? 1) && $hubPage - $page < self::EMPTY_PAGES_READ_AHEAD) {
            $hubPage++;
            $result = $this->hub->threads($account->propertyware_id, $hubPage, $filters);
            $withMessages = $this->withMessages($result);
        }

        $threads = array_values(array_map(fn (array $thread): array => $this->presentThread($thread), $withMessages));

        return [
            'threads' => $reader === null ? $threads : $this->withUnread($reader, $threads),
            'currentPage' => (int) ($result['meta']['current_page'] ?? 1),
            'lastPage' => (int) ($result['meta']['last_page'] ?? 1),
            'total' => (int) ($result['meta']['total'] ?? 0),
        ];
    }

    /**
     * The threads on one page of the hub's answer that hold at least one message.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private function withMessages(array $result): array
    {
        return array_values(array_filter(
            is_array($result['data'] ?? null) ? $result['data'] : [],
            fn (mixed $thread): bool => is_array($thread) && isset($thread['last_message']),
        ));
    }

    /**
     * The client's conversations with a reply they haven't read, newest first.
     * Only the latest page of conversations is checked, which covers anything recent.
     *
     * @return array{count: int, items: array<int, array<string, mixed>>}
     *
     * @throws ChatHubException
     */
    public function unread(User $reader, ClientAccount $account, int $limit = 5): array
    {
        $unread = array_values(array_filter(
            $this->threads($account, 1, [], $reader)['threads'],
            fn (array $thread): bool => $thread['unread'],
        ));

        return ['count' => count($unread), 'items' => array_slice($unread, 0, $limit)];
    }

    /**
     * Remember that the reader has seen a conversation up to its latest message.
     *
     * @param  array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}  $conversation
     */
    public function markRead(User $reader, array $conversation): void
    {
        $lastId = (int) (end($conversation['messages'])['id'] ?? 0);

        if ($lastId === 0) {
            return;
        }

        $read = ConversationRead::firstOrNew(['user_id' => $reader->id, 'thread_id' => $conversation['thread']['id']]);

        if (! $read->exists || $read->last_read_message_id < $lastId) {
            $read->fill(['last_read_message_id' => $lastId])->save();
        }
    }

    /**
     * Flag threads whose newest message is from TexasRenters.com and newer than what the reader saw.
     *
     * @param  array<int, array<string, mixed>>  $threads
     * @return array<int, array<string, mixed>>
     */
    private function withUnread(User $reader, array $threads): array
    {
        $reads = ConversationRead::query()
            ->where('user_id', $reader->id)
            ->whereIn('thread_id', array_column($threads, 'id'))
            ->pluck('last_read_message_id', 'thread_id');

        return array_map(fn (array $thread): array => [
            ...$thread,
            'unread' => $thread['lastMessage'] !== null
                && ! $thread['lastMessage']['fromClient']
                && $thread['lastMessage']['id'] > (int) ($reads[$thread['id']] ?? 0),
        ], $threads);
    }

    /**
     * One of the client's threads with its messages, or null when it isn't theirs.
     *
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}|null
     *
     * @throws ChatHubException
     */
    public function conversation(ClientAccount $account, int $threadId): ?array
    {
        $thread = $this->ownThread($account, $threadId);

        if ($thread === null) {
            return null;
        }

        return [
            // The client's own number, so they know where our texts reach them.
            // It is theirs alone: the thread was just checked against their contact.
            'thread' => [...$this->presentThread($thread), 'phone' => $this->presentPhone($thread['phone'] ?? null)],
            'messages' => array_map(fn (array $message): array => $this->presentMessage($message), $this->hub->messages($threadId)),
        ];
    }

    /**
     * Whether the client asked us to text our replies, and the number we would
     * text. Null when the hub isn't set up or doesn't know this contact yet.
     *
     * @return array{enabled: bool, phone: ?string}|null
     */
    public function smsReplies(ClientAccount $account): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        try {
            $contact = $this->hub->contact($account->propertyware_id);
        } catch (ChatHubException) {
            return null;
        }

        return [
            'enabled' => (bool) ($contact['sms_replies'] ?? false),
            'phone' => $this->presentPhone($contact['phone'] ?? null),
        ];
    }

    /**
     * Turn texted replies on or off for this client.
     *
     * @throws ChatHubException
     */
    public function setSmsReplies(ClientAccount $account, bool $wanted): void
    {
        $this->hub->setSmsReplies($account->propertyware_id, $wanted);
    }

    /**
     * Start a conversation with TexasRenters.com and post its first message.
     *
     * The category the client picked lets the chatbot answer their question
     * straight away; without one the hub starts its own menu, as a text does.
     * An owner's chosen property tells staff which building it is about.
     *
     * @param  array<int, UploadedFile>  $attachments
     * @return array<string, mixed> The new thread.
     *
     * @throws ChatHubException
     */
    public function start(ClientAccount $account, ?string $subject, string $body, array $attachments = [], ?string $category = null, ?int $propertyId = null): array
    {
        $thread = $this->hub->openThread($account->propertyware_id, $subject, $category, $propertyId);
        $this->post($account, (int) $thread['id'], $body, (string) Str::uuid(), $attachments);

        return $this->presentThread($thread);
    }

    /**
     * Post the client's reply, or return false when the thread isn't theirs.
     *
     * @throws ChatHubException
     */
    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function reply(ClientAccount $account, int $threadId, ?string $body, string $idempotencyKey, array $attachments = []): bool
    {
        if ($this->ownThread($account, $threadId) === null) {
            return false;
        }

        $this->post($account, $threadId, $body, $idempotencyKey, $attachments);

        return true;
    }

    /**
     * Post the text with the first file, then each further file as its own
     * message, since a hub message (like a text) carries one attachment.
     * Every part has its own key, so a retry never duplicates any of them.
     *
     * The client's other open pages and devices are told at once, rather than
     * waiting for the hub's webhook to come back round.
     *
     * @param  array<int, UploadedFile>  $attachments
     *
     * @throws ChatHubException
     */
    private function post(ClientAccount $account, int $threadId, ?string $body, string $idempotencyKey, array $attachments): void
    {
        $attachments = array_values($attachments);

        $this->hub->postClientMessage($threadId, $body, $idempotencyKey, $attachments[0] ?? null);

        foreach (array_slice($attachments, 1) as $index => $attachment) {
            $this->hub->postClientMessage($threadId, null, $idempotencyKey.':'.($index + 1), $attachment);
        }

        ClientConversationUpdated::dispatch($account->propertyware_id, $threadId);
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws ChatHubException
     */
    private function ownThread(ClientAccount $account, int $threadId): ?array
    {
        try {
            $thread = $this->hub->thread($threadId);
        } catch (ChatHubException $exception) {
            if (str_contains($exception->getMessage(), '404')) {
                return null;
            }

            throw $exception;
        }

        return (string) ($thread['contact_id'] ?? '') === (string) $account->propertyware_id ? $thread : null;
    }

    /**
     * A US number as the client knows it, or whatever we hold when it is not one.
     */
    private function presentPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
        $digits = strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;

        if (blank($phone)) {
            return null;
        }

        return strlen($digits) === 10
            ? sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6))
            : $phone;
    }

    /**
     * @param  array<string, mixed>  $thread
     * @return array<string, mixed>
     */
    private function presentThread(array $thread): array
    {
        $isMaintenance = filled($thread['work_order_id'] ?? null);

        return [
            'id' => (int) $thread['id'],
            'subject' => $thread['subject'] ?? ($isMaintenance ? 'Maintenance' : 'Conversation with TexasRenters.com'),
            'kind' => $isMaintenance ? 'maintenance' : 'support',
            // What the client said the conversation is about, when they picked one.
            'category' => in_array($thread['type'] ?? null, ['leasing', 'accounting', 'maintenance'], true) ? $thread['type'] : null,
            'workOrderId' => $thread['work_order_id'] ?? null,
            'workOrderNumber' => $thread['work_order_number'] ?? null,
            'isClosed' => ($thread['status'] ?? null) === 'closed',
            // The staff member handling the conversation, shown in the list
            // instead of a generic icon. Null until someone picks it up.
            'assignedStaff' => blank($thread['assigned_to'] ?? null) ? null : [
                'name' => $thread['assigned_to'],
                'photoUrl' => $thread['assigned_to_photo_url'] ?? null,
            ],
            'updatedAt' => $thread['last_message']['created_at'] ?? $thread['updated_at'] ?? null,
            'lastMessage' => isset($thread['last_message']) ? [
                'id' => (int) ($thread['last_message']['id'] ?? 0),
                'author' => match ($thread['last_message']['sender'] ?? null) {
                    'user' => null,
                    'bot' => 'TexasRenters.com Assistant',
                    default => $thread['last_message']['sender_name'] ?? 'TexasRenters.com',
                },
                'preview' => $thread['last_message']['body'] ?? '',
                'fromClient' => ($thread['last_message']['direction'] ?? null) === 'inbound',
                'hasAttachment' => (bool) ($thread['last_message']['has_attachment'] ?? false),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function presentMessage(array $message): array
    {
        $fromClient = ($message['direction'] ?? null) === 'inbound';

        return [
            'id' => (int) $message['id'],
            'body' => $message['body'] ?? '',
            'mediaUrls' => $message['media_urls'] ?? [],
            'fromClient' => $fromClient,
            'author' => $fromClient ? null : match ($message['sender'] ?? null) {
                'bot' => 'TexasRenters.com Assistant',
                default => filled($message['sender_name'] ?? null) ? $message['sender_name'] : 'TexasRenters.com',
            },
            'authorPhotoUrl' => $fromClient || ($message['sender'] ?? null) === 'bot' ? null : ($message['sender_photo_url'] ?? null),
            // The number this text used, on the client's own conversation. Messages
            // written in the app carry none, and then the bubble shows none.
            'number' => $this->presentPhone($message['sender_number'] ?? null),
            'at' => $message['created_at'] ?? null,
        ];
    }
}
