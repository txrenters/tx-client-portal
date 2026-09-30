<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One of a login's meetings was booked, moved or cancelled, so its Meetings
 * page (web or app) reloads what is booked. Nothing about the meeting is
 * sent; the page asks scheduling for the rest.
 */
class MeetingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(self::channelFor($this->userId));
    }

    public function broadcastAs(): string
    {
        return 'meetings.updated';
    }

    /**
     * @return array<string, never>
     */
    public function broadcastWith(): array
    {
        return [];
    }

    /**
     * The login's own private channel, without Echo's "private-" prefix.
     */
    public static function channelFor(int $userId): string
    {
        return "App.Models.User.{$userId}";
    }
}
