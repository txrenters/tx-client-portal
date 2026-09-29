<?php

namespace App\Notifications;

use App\Enums\StaffRole;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone they now have staff access. A brand new login also gets a
 * link to choose their password, which is the only way in: nobody else ever
 * knows it.
 */
class StaffAccessGrantedNotification extends Notification
{
    use Queueable;

    public function __construct(public StaffRole $role, public ?string $passwordToken = null) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('You have staff access to the TexasRenters.com portal'))
            ->greeting(__('Hi :name,', ['name' => $notifiable->name]))
            ->line(__('You have been given :role access to the admin area of the client portal.', ['role' => mb_strtolower($this->role->label())]));

        if ($this->passwordToken !== null) {
            return $message
                ->line(__('Choose a password to sign in for the first time.'))
                ->action(__('Choose your password'), route('password.reset', ['token' => $this->passwordToken, 'email' => $notifiable->email]))
                ->line(__('For your security, turn on two-factor authentication in Settings once you are in.'));
        }

        return $message->action(__('Open the admin area'), route('admin.dashboard'));
    }
}
