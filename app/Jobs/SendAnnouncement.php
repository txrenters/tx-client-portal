<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Notifications\Channels\ExpoChannel;
use App\Services\Announcements\AnnouncementBoard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Email and/or push an announcement to its audience, in batches so one
 * large send never holds a worker or a mail connection for too long.
 */
class SendAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $announcementId, public bool $email, public bool $push) {}

    public function handle(AnnouncementBoard $board): void
    {
        $announcement = Announcement::find($this->announcementId);

        if ($announcement === null) {
            return;
        }

        $board->audienceQuery($announcement->audience)
            ->with('pushTokens')
            ->chunkById(200, function ($users) use ($announcement): void {
                $users->each(function (User $user) use ($announcement): void {
                    $channels = array_values(array_filter([
                        $this->email ? 'mail' : null,
                        $this->push && $user->pushTokens->isNotEmpty() ? ExpoChannel::class : null,
                    ]));

                    if ($channels !== []) {
                        $user->notify(new AnnouncementNotification($announcement, $channels));
                    }
                });
            });
    }
}
