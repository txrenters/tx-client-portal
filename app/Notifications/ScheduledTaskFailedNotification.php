<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the owners a scheduled command started failing, such as sending
 * approvals to Propertyware, so it is not left broken until someone happens to
 * open the admin area.
 *
 * Sent straight away rather than queued: a broken queue may be the reason.
 */
class ScheduledTaskFailedNotification extends Notification
{
    use Queueable;

    public function __construct(public string $command, public string $reason) {}

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
            ->error()
            ->subject(__('Scheduled job failing: :command', ['command' => $this->command]))
            ->line(__('The scheduled job :command failed. You will not be emailed again until it has run successfully.', ['command' => $this->command]))
            ->line(Str::limit($this->reason, 500))
            ->action(__('Open integrations'), route('admin.integrations.index'));
    }
}
