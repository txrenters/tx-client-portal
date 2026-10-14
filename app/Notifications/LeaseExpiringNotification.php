<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;

/**
 * A lease is 90 days from ending. Always kept in the client's notification
 * list; pushed to their phones unless they turned lease reminders off.
 *
 * Sent straight from the daily command, not queued: the command records the
 * lease only after this returns, so a failure leaves it for tomorrow.
 */
class LeaseExpiringNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $notice  built by LeaseNoticeText
     */
    public function __construct(public array $notice) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $push = $notifiable instanceof User && $notifiable->wantsNotification('lease_expiry_push');

        return $push ? ['database', ExpoChannel::class] : ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->notice;
    }

    /**
     * `notificationId` lets the app open this notice itself; `accountId`
     * switches to the account it belongs to first.
     */
    public function toExpo(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->notice['title'],
            body: $this->notice['body'],
            data: [
                'type' => 'lease_expiring',
                'accountId' => $this->notice['account_id'],
                'leaseId' => $this->notice['lease_id'],
                'buildingId' => $this->notice['building_id'],
                'notificationId' => $this->id,
                'url' => $this->notice['url'],
            ],
        );
    }
}
