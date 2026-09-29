<?php

namespace App\Notifications;

use App\Enums\ClientRole;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * TexasRenters.com replied in the portal to a client who wrote there, so the
 * reply wasn't texted; this lets them know it's waiting.
 *
 * Which channels carry it is decided by the caller, because the two are gated
 * differently: the app is pushed every reply, while the email is held back to
 * one per conversation every half hour so a quick back-and-forth doesn't flood
 * an inbox.
 */
class StaffRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public ClientRole $role,
        public int $threadId,
        public string $subject,
        public ?string $author,
        public string $preview,
        public ?int $accountId = null,
        public array $channels = ['mail'],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('New reply: :subject', ['subject' => Str::limit($this->subject, 60)]))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(__(':author replied to your message ":subject".', [
                'author' => $this->author ?? 'TexasRenters.com',
                'subject' => $this->subject,
            ]))
            ->line(Str::limit($this->preview, 300))
            ->action(__('View message'), $this->threadUrl())
            ->line(__('You can reply right there.'));
    }

    /**
     * The notification as it arrives on the phone. `type` and `threadId` are
     * what the app reads to open the conversation the tap was about, and
     * `accountId` lets it switch to the portfolio the reply belongs to first.
     */
    public function toExpo(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->author ?? 'TexasRenters.com',
            // Expo caps the whole payload at about 4KiB, and a lock screen
            // shows far less than that anyway.
            body: Str::limit($this->preview, 180),
            data: array_filter([
                'type' => 'message',
                'threadId' => $this->threadId,
                'accountId' => $this->accountId,
                'url' => $this->threadUrl(),
            ], fn (mixed $value): bool => $value !== null),
            channelId: 'messages',
        );
    }

    private function threadUrl(): string
    {
        return route($this->role->value.'.messages.index', ['thread' => $this->threadId]);
    }
}
