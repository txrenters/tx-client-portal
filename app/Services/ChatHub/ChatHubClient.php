<?php

namespace App\Services\ChatHub;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the messaging hub in tx-chatbot, which holds every conversation
 * between TexasRenters.com and its clients, by text or in this app.
 */
class ChatHubClient
{
    /**
     * Seconds to wait for the hub to take a client's message, bot answer included.
     */
    private const CLIENT_MESSAGE_TIMEOUT = 35;

    /**
     * @param  bool|string  $verify  Whether to check TLS, or a CA bundle path for local .test sites.
     */
    public function __construct(
        private ?string $url,
        private ?string $token,
        private bool|string $verify = true,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->url) && filled($this->token);
    }

    /**
     * Check the hub is answering, without touching any conversation.
     *
     * The hub publishes no health endpoint, so this only asks whether it
     * responds at all: any HTTP reply means the host and its certificate are
     * fine, and only a failure to connect counts as down. It deliberately does
     * not read a thread, because the cheapest conversation read still returns
     * a client's messages.
     *
     * @throws ChatHubException
     */
    public function ping(): void
    {
        if (! $this->isConfigured()) {
            throw new ChatHubException('The messaging hub is not configured.');
        }

        try {
            Http::withToken((string) $this->token)
                ->acceptJson()
                ->withOptions(['verify' => $this->verify])
                ->timeout(8)
                ->connectTimeout(5)
                ->get(rtrim((string) $this->url, '/').'/');
        } catch (ConnectionException $exception) {
            throw new ChatHubException('Could not reach the messaging hub.', previous: $exception);
        }
    }

    /**
     * A contact's threads, most recent activity first.
     *
     * @param  array{search?: ?string, status?: ?string, category?: ?string, from?: ?string, to?: ?string}  $filters  Status "open" or "closed"; category is the thread's department; dates as Y-m-d, matching message activity.
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws ChatHubException
     */
    public function threads(string $contactId, int $page = 1, array $filters = []): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('threads', array_filter([
            'contact_id' => $contactId,
            'page' => $page,
            'search' => $filters['search'] ?? null,
            // The hub calls the client's category a thread type.
            'type' => $filters['category'] ?? null,
            // The hub's "active" is every thread that isn't closed.
            'status' => match ($filters['status'] ?? null) {
                'open' => 'active',
                'closed' => 'closed',
                default => null,
            },
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
            // Conversations still going come before the ones we have closed.
            'sort' => 'open_first',
        ], filled(...))))->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ChatHubException
     */
    public function thread(int $threadId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get("threads/{$threadId}"))->json('data');
    }

    /**
     * A thread's messages, oldest first.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ChatHubException
     */
    public function messages(int $threadId): array
    {
        $messages = [];
        $afterId = 0;

        // The hub pages 100 at a time; a long thread is read in a few requests.
        do {
            $page = $this->send(fn (PendingRequest $http) => $http->get("threads/{$threadId}/messages", ['after_id' => $afterId]))->json('data') ?? [];
            $messages = [...$messages, ...$page];
            $afterId = (int) (end($page)['id'] ?? $afterId);
        } while (count($page) === 100 && count($messages) < 1000);

        return $messages;
    }

    /**
     * Open a new conversation for a contact.
     *
     * @return array<string, mixed>
     *
     * @throws ChatHubException
     */
    public function openThread(string $contactId, ?string $subject, ?string $category = null, ?int $buildingId = null): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post('threads', array_filter([
            'contact_id' => $contactId,
            'subject' => $subject,
            // The hub skips its category menu and names the thread when it knows this.
            'category' => $category,
            // The Propertyware building the message is about, so staff see which property.
            'building_id' => $buildingId === null ? null : (string) $buildingId,
        ], filled(...))))->json('data');
    }

    /**
     * What the hub does when we reply to this contact, and the number it texts.
     *
     * @return array<string, mixed>
     *
     * @throws ChatHubException
     */
    public function contact(string $contactId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get("contacts/{$contactId}"))->json('data');
    }

    /**
     * Ask the hub to text this contact our replies, or to stop.
     *
     * @return array<string, mixed>
     *
     * @throws ChatHubException
     */
    public function setSmsReplies(string $contactId, bool $wanted): array
    {
        return $this->send(fn (PendingRequest $http) => $http->patch("contacts/{$contactId}", ['sms_replies' => $wanted]))->json('data');
    }

    /**
     * Post a message the client wrote in this app. Retrying with the same key never duplicates it.
     *
     * @return array<string, mixed>
     *
     * @throws ChatHubException
     */
    public function postClientMessage(int $threadId, ?string $body, string $idempotencyKey, ?UploadedFile $attachment = null): array
    {
        return $this->send(function (PendingRequest $http) use ($threadId, $body, $idempotencyKey, $attachment) {
            // The hub has its bot answer before it responds, which can outlast the
            // usual limit; giving up then reports a failure for a message it kept.
            $http = $http->timeout(self::CLIENT_MESSAGE_TIMEOUT);

            if ($attachment !== null) {
                $http = $http->attach('attachment', (string) file_get_contents($attachment->getRealPath()), $attachment->getClientOriginalName());
            }

            return $http->post("threads/{$threadId}/messages", array_filter([
                'body' => $body,
                'from' => 'client',
                'idempotency_key' => $idempotencyKey,
            ], filled(...)));
        })->json('data');
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws ChatHubException
     */
    private function send(callable $request): Response
    {
        if (! $this->isConfigured()) {
            throw new ChatHubException('The messaging hub is not configured.');
        }

        try {
            $response = $request(Http::baseUrl(rtrim((string) $this->url, '/').'/')
                ->withToken((string) $this->token)
                ->acceptJson()
                ->withOptions(['verify' => $this->verify])
                ->timeout(10)
                ->connectTimeout(5));
        } catch (ConnectionException $exception) {
            throw new ChatHubException('Could not reach the messaging hub.', previous: $exception);
        }

        if ($response->failed()) {
            throw new ChatHubException("The messaging hub answered {$response->status()}.");
        }

        return $response;
    }
}
