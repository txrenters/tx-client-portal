<?php

namespace App\Notifications;

use App\Models\ClientAccount;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Services\Propertyware\Data\Lease;
use App\Services\Push\PushMessage;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * A tenant still owes rent early in the month, before late fees start.
 * Always kept in their notification list; pushed to their phones unless they
 * turned rent reminders off.
 *
 * Rent is paid in the Propertyware tenant portal, not the portal or the app:
 * `url` opens the Lease page (a notice never leaves the portal) and `pay_url`
 * is the tenant portal, for the app to open directly.
 *
 * Sent straight from the daily command, not queued: the command records the
 * month only after this returns, so a failure is tried again tomorrow.
 */
class RentDueNotification extends Notification
{
    /**
     * @var array<string, mixed>
     */
    public array $notice;

    public function __construct(ClientAccount $account, Lease $lease, CarbonInterface $payBy)
    {
        $owed = Number::currency((float) $lease->balance, 'USD');
        $body = "You owe {$owed}. Pay by {$payBy->format('M j')} to avoid a late fee.";

        $this->notice = [
            'type' => 'rent_due',
            'role' => $account->role->value,
            'account_id' => $account->id,
            'lease_id' => $lease->id,
            'balance' => $lease->balance,
            'pay_by' => $payBy->toDateString(),
            'title' => 'Your rent is due',
            'body' => $body,
            'message' => $body."\nRent is paid in your Propertyware tenant portal. If you have already paid, it can take a few days to show here.",
            'url' => route('resident.lease'),
            'pay_url' => config('services.propertyware.portal_urls.resident'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $push = $notifiable instanceof User && $notifiable->wantsNotification('rent_reminders_push');

        return $push ? ['database', ExpoChannel::class] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->notice;
    }

    public function toExpo(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->notice['title'],
            body: $this->notice['body'],
            data: [
                'type' => 'rent_due',
                'accountId' => $this->notice['account_id'],
                'notificationId' => $this->id,
                'url' => $this->notice['url'],
                'payUrl' => $this->notice['pay_url'],
            ],
        );
    }
}
