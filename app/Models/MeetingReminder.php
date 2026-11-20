<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A meeting reminder that was sent, so each one goes out once.
 *
 * @property string $booking_uid
 * @property string $kind
 */
#[Fillable(['user_id', 'booking_uid', 'kind', 'starts_at'])]
class MeetingReminder extends Model
{
    use Prunable;

    /** An hour before the meeting. */
    public const HOUR = 'hour';

    /** Five minutes before the meeting. */
    public const FIVE_MINUTES = 'five_minutes';

    /**
     * A reminder is only needed until its meeting has gone.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('starts_at', '<', now()->subDays(7));
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
        ];
    }
}
