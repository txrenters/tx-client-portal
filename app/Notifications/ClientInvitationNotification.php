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
            ->line(__('We are building a new client portal that brings everything TexasRenters.com does for you into one place, and your :portal is ready for you to test.', [
                'portal' => mb_strtolower($this->invitation->role->portalLabel()),
            ]))
            ->line(__('You can check for updates anytime. From one login you can view your property information, follow your maintenance requests, message our team, and find your documents.'))
            ->line(__('Our iPhone app is now on the App Store, and the same login works there: :url. Our Android app is coming soon.', ['url' => config('mobile.ios.app_store_url')]))
            ->line(__('We are adding new features all the time, and your feedback helps shape what comes next.'))
            ->action(__('Set up your account'), route('invitations.show', $this->token))
            ->line(__('Create your account with the email address connected to your Propertyware account. That is this address, so it is already filled in for you: just choose a name and password, and you are in. This link expires in :days days.', ['days' => InviteClient::EXPIRES_AFTER_DAYS]))
            ->line(__('Questions? Visit our support site at :url or text us at (281) 248-8018.', ['url' => route('support')]))
            ->salutation(__("Thank you,\nThe TexasRenters.com Team"));
    }
}
