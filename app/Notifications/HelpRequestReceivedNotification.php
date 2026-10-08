<?php

namespace App\Notifications;

use App\Models\HelpRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells staff a client asked for help with the portal or the app, so a
 * request is not left waiting until someone happens to open the admin area.
 */
class HelpRequestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public HelpRequest $helpRequest) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->helpRequest->loadMissing('user:id,name,email');

        return (new MailMessage)
            ->subject(__('Help request: :topic', ['topic' => $request->topic->label()]))
            ->line(__(':name (:email) asked for help from the :platform.', [
                'name' => $request->user->name,
                'email' => $request->user->email,
                'platform' => $request->platform === 'web' ? 'web portal' : $request->platform.' app',
            ]))
            ->line(Str::limit($request->message, 500))
            ->when($request->attachments()->exists(), fn (MailMessage $mail): MailMessage => $mail->line(trans_choice(
                '{1} They attached a file; see it in the admin area.|[2,*] They attached :count files; see them in the admin area.',
                $request->attachments()->count(),
            )))
            ->action(__('Open help requests'), route('admin.help-requests.index'));
    }
}
