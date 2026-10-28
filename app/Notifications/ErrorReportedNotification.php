<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells the owners an unexpected error happened in production.
 *
 * Sent straight away rather than queued: the queue may be what broke.
 */
class ErrorReportedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $exceptionClass,
        public string $exceptionMessage,
        public string $location,
        public ?string $requestLine,
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
            ->error()
            ->subject(__('Portal error: :class', ['class' => class_basename($this->exceptionClass)]))
            ->line(Str::limit($this->exceptionMessage, 500) ?: $this->exceptionClass)
            ->line(__('Thrown at :location.', ['location' => $this->location]))
            ->when($this->requestLine !== null, fn (MailMessage $mail): MailMessage => $mail->line(__('While handling :request.', ['request' => $this->requestLine])))
            ->line(__('The same error is not emailed again for an hour. The log has the full trace.'))
            ->action(__('Open the log'), url('/log-viewer'));
    }
}
