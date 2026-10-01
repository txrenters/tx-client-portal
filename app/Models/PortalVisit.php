<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * A signed-in person coming back to the portal or the app after half an
 * hour or more away; see TrackLastSeen.
 *
 * @property int $user_id
 * @property string $channel web or app.
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'channel'])]
class PortalVisit extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** How long visits are kept. */
    public const KEEP_DAYS = 400;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
