<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * Something an anonymous visitor did on the landing page; see
 * LandingPageTracker. Each visitor, event and day is counted once.
 *
 * @property Carbon $day
 * @property string $event view, tutorial_play, sign_up or sign_in.
 * @property string $visitor A hash that changes every day, so nobody can be followed across days.
 * @property Carbon $created_at
 */
#[Fillable(['day', 'event', 'visitor'])]
class LandingPageEvent extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** How long landing page events are kept, as for portal visits. */
    public const KEEP_DAYS = PortalVisit::KEEP_DAYS;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('day', '<', now()->subDays(self::KEEP_DAYS)->toDateString());
    }
}
