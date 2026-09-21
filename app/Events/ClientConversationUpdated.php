<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something changed in one of a contact's conversations, so the Messages page
 * (web or app) reloads it. Only ids are sent; the page fetches the rest.
 */
class ClientConversationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $contactId, public int $threadId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("contacts.{$this->contactId}.messages");
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /**
     * @return array{threadId: int}
     */
    public function broadcastWith(): array
    {
        return ['threadId' => $this->threadId];
    }
}
