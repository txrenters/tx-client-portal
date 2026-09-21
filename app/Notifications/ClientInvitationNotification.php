<?php

namespace App\Notifications;

use App\Actions\Client\InviteClient;
use App\Models\ClientInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ClientInvitation $invitation,
        public string $token,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You\'re invited to the TexasRenters.com client portal'))
            ->greeting(__('Hi :name,', ['name' => $this->invitation->name]))
            ->line(__('TexasRenters.com has set up your :portal. Use it to view your property information, maintenance requests, messages, and documents in one place.', [
                'portal' => mb_strtolower($this->invitation->role->portalLabel()),
            ]))
            ->action(__('Set up your account'), route('invitations.show', $this->token))
            ->line(__('This link expires in :days days.', ['days' => InviteClient::EXPIRES_AFTER_DAYS]));
    }
}
