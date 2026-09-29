<?php

namespace App\Notifications\Channels;

use App\Models\User;
use App\Services\Push\ExpoPushClient;
use App\Services\Push\ExpoPushException;
use App\Services\Push\PushMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Delivers a notification to every phone the client has registered.
 *
 * A notification opts in by listing this channel in `via()` and answering
 * `toExpo()` with a PushMessage.
 */
class ExpoChannel
{
    public function __construct(private ExpoPushClient $expo) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toExpo')) {
            return;
        }

        $devices = $notifiable->pushTokens;

        if ($devices->isEmpty()) {
            return;
        }

        $message = $notification->toExpo($notifiable);

        if (! $message instanceof PushMessage) {
            return;
        }

        try {
            $this->expo->send($devices, $message);
        } catch (ExpoPushException $exception) {
            // A push that cannot be sent is not worth failing the notification
            // over: the email beside it still goes, and the app shows the same
            // reply the moment it is opened.
            Log::warning('Could not send a push notification.', [
                'user_id' => $notifiable->getKey(),
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
