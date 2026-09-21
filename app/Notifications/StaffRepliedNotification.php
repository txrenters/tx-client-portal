<?php

namespace App\Notifications;

use App\Enums\ClientRole;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * TexasRenters.com replied in the portal to a client who wrote there, so the
 * reply wasn't texted; this email lets them know it's waiting.
 */
class StaffRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ClientRole $role,
        public int $threadId,
        public string $subject,
        public ?string $author,
        public string $preview,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
            ->action(__('View message'), route($this->role->value.'.messages.index', ['thread' => $this->threadId]))
            ->line(__('You can reply right there.'));
    }
}
