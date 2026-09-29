<?php

namespace App\Services\Push;

/**
 * One notification as Expo's push service wants it, minus the recipient.
 *
 * The same message goes to every phone a client has registered, so the token
 * is added per device when the batch is built.
 */
class PushMessage
{
    /**
     * @param  array<string, mixed>  $data  What the app reads to decide which screen a tap opens; keep it small, Expo caps the payload at about 4KiB.
     * @param  string  $channelId  The Android notification channel, which decides how loudly it arrives. The app creates "default", "messages" and "approvals".
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public string $channelId = 'default',
        public string $priority = 'high',
        public ?int $badge = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $token): array
    {
        return array_filter([
            'to' => $token,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'sound' => 'default',
            'badge' => $this->badge,
            'channelId' => $this->channelId,
            'priority' => $this->priority,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
