<?php

namespace App\Notifications;

use App\Models\MeetingReminder;
use App\Models\User;
use App\Notifications\Channels\ExpoChannel;
use App\Services\Push\PushMessage;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;

/**
 * An owner's meeting starts in an hour, or in five minutes. Always kept in
 * their notification list; pushed to their phones unless they turned meeting
 * reminders off.
 *
 * A meeting belongs to the owner's login rather than one property account,
 * so the notice carries every owner account they have and shows under each.
 * Sent straight from the minute-by-minute command, not queued: the command
 * records the reminder only after this returns, so a failure is tried again.
 */
class MeetingReminderNotification extends Notification
{
    public const TIMEZONE = 'America/Chicago';

    /**
     * @var array<string, mixed>
     */
    public array $notice;

    /**
     * @param  array<int, int>  $accountIds  The owner's accounts, the first one opened from a push.
     */
    public function __construct(
        array $accountIds,
        string $bookingUid,
        string $meetingName,
        CarbonImmutable $startsAt,
        string $kind,
        ?CarbonImmutable $now = null,
    ) {
        $minutes = max(1, (int) round(($now ?? CarbonImmutable::now())->diffInMinutes($startsAt, absolute: true)));

        $in = match (true) {
            $kind === MeetingReminder::HOUR && $minutes >= 55 => 'in 1 hour',
            $minutes === 1 => 'in 1 minute',
            default => "in {$minutes} minutes",
        };

        $at = $startsAt->setTimezone(self::TIMEZONE)->format('g:i A');

        $this->notice = [
            'type' => 'meeting_reminder',
            'role' => 'owner',
            'account_id' => array_values($accountIds),
            'booking_uid' => $bookingUid,
            'reminder' => $kind,
            'title' => "Your meeting starts {$in}",
            'body' => "{$meetingName} at {$at}",
            'message' => "{$meetingName} starts {$in}, at {$at} (Houston time).",
            'url' => route('owner.meetings.index'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $push = $notifiable instanceof User && $notifiable->wantsNotification('meeting_reminders_push');

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
     * `meetings` opens the Meetings screen in the app; `accountId` switches to
     * one of the owner's accounts first if the app is on a tenant account.
     */
    public function toExpo(object $notifiable): PushMessage
    {
        return new PushMessage(
            title: $this->notice['title'],
            body: $this->notice['body'],
            data: [
                'type' => 'meeting_reminder',
                'bookingUid' => $this->notice['booking_uid'],
                'accountId' => $this->notice['account_id'][0] ?? null,
                'notificationId' => $this->id,
                'url' => $this->notice['url'],
            ],
        );
    }
}
