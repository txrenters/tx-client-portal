<?php

namespace App\Models;

use App\Enums\ClientRole;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A notice from TexasRenters.com shown across the portal and the apps, such
 * as an office closure or a change to how rent is paid.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property string $audience all, owner or resident.
 * @property string $tone info or warning.
 * @property ?Carbon $starts_at
 * @property ?Carbon $ends_at
 * @property ?Carbon $emailed_at
 * @property ?Carbon $pushed_at
 * @property ?int $created_by
 * @property Carbon $created_at
 */
#[Fillable(['title', 'body', 'audience', 'tone', 'starts_at', 'ends_at', 'created_by'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /**
     * Announcements showing right now.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * Announcements meant for anyone holding one of these roles.
     *
     * @param  Builder<self>  $query
     * @param  iterable<int, ClientRole>  $roles
     */
    public function scopeForRoles(Builder $query, iterable $roles): void
    {
        $values = collect($roles)->map(fn (ClientRole $role): string => $role->value)->all();

        $query->whereIn('audience', ['all', ...$values]);
    }

    public function isLive(): bool
    {
        return ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /**
     * @return HasMany<AnnouncementDismissal, $this>
     */
    public function dismissals(): HasMany
    {
        return $this->hasMany(AnnouncementDismissal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'emailed_at' => 'datetime',
            'pushed_at' => 'datetime',
        ];
    }
}
