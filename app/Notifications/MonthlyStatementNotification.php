<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Last month's income statement, one PDF per portfolio, emailed to an owner
 * who turned monthly statements on.
 *
 * Sent straight from the monthly command, not queued: the PDFs are made for
 * the email and never stored, and the command records the month only after
 * this returns.
 */
class MonthlyStatementNotification extends Notification
{
    /**
     * @param  array<int, array{name: string, contents: string}>  $statements
     */
    public function __construct(public string $monthLabel, public array $statements) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Your income statement for :month', ['month' => $this->monthLabel]))
            ->line(trans_choice(
                '{1} Your income statement for :month is attached.|[2,*] Your income statements for :month are attached, one for each portfolio.',
                count($this->statements),
                ['month' => $this->monthLabel],
            ))
            ->line(__('You can make one for any other months on the Statements page.'))
            ->action(__('Open statements'), route('owner.statements'))
            ->line(__('You get this because monthly statements are on in your notification settings.'));

        foreach ($this->statements as $statement) {
            $mail->attachData($statement['contents'], $statement['name'], ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
