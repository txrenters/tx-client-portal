<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's history with one promotion: the day it last popped up for
 * them, whether they tapped through, and whether they turned it off.
 *
 * @property ?Carbon $last_shown_on
 * @property int $times_shown
 * @property ?Carbon $clicked_at
 * @property ?Carbon $hidden_at
 */
#[Fillable(['promotion_id', 'user_id', 'last_shown_on', 'times_shown', 'clicked_at', 'hidden_at'])]
class PromotionView extends Model
{
    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'last_shown_on' => 'date',
            'times_shown' => 'integer',
            'clicked_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }
}
