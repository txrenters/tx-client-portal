<?php

namespace App\Notifications;

use App\Models\Announcement;
use App\Services\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A staff announcement, by email and/or to the app. The banner in the portal
 * is separate: it shows whether or not this is sent.
 */
class AnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(public Announcement $announcement, public array $channels = ['mail']) {}

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
            ->subject($this->announcement->title)
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line($this->announcement->body)
            ->action(__('Open the portal'), route('dashboard'));
    }

    public function toExpo(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->announcement->title,
            body: Str::limit($this->announcement->body, 180),
            data: ['type' => 'announcement', 'announcementId' => $this->announcement->id],
        );
    }
}
