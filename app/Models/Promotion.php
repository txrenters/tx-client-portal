<?php

namespace App\Models;

use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A TexasRenters.com promotion clients see as a popup, at most once a day,
 * on the web portal and in the apps. Staff manage them in the admin area.
 *
 * @property string $title
 * @property string $body
 * @property string $button_label
 * @property string $button_link "referrals" for the Refer a friend page, or a full https:// address.
 * @property string $audience all, owner or resident.
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 * @property bool $is_active
 * @property int $priority Higher shows first when several are running.
 * @property bool $stop_after_referral
 * @property string $theme The popup's colours; one of THEMES.
 * @property string $icon The popup's picture; one of ICONS.
 */
#[Fillable([
    'title', 'body', 'button_label', 'button_link', 'audience', 'starts_at', 'ends_at',
    'is_active', 'priority', 'stop_after_referral', 'theme', 'icon',
])]
class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory;

    public const LINK_REFERRALS = 'referrals';

    /**
     * The colour schemes staff can pick. Each app maps the name to its own
     * palette; the web's is in resources/js/lib/promotionDesign.ts.
     *
     * @var array<int, string>
     */
    public const THEMES = ['blue', 'green', 'violet', 'ocean', 'red', 'charcoal'];

    /**
     * The pictures staff can pick, named as lucide icons.
     *
     * @var array<int, string>
     */
    public const ICONS = [
        'gift', 'megaphone', 'sparkles', 'party-popper', 'house', 'key-round',
        'calendar', 'percent', 'heart', 'star', 'bell', 'wrench',
    ];

    /**
     * @return HasMany<PromotionView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(PromotionView::class);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
            'stop_after_referral' => 'boolean',
        ];
    }
}
