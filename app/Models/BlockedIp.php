<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An IP address the portal refuses; see IpGuard.
 *
 * @property int $id
 * @property string $ip_address
 * @property string $reason
 * @property string $source manual or automatic.
 * @property ?Carbon $expires_at Null blocks for good.
 * @property ?int $blocked_by
 * @property int $hits Requests refused since the block.
 * @property ?Carbon $last_hit_at
 * @property Carbon $created_at
 */
#[Fillable(['ip_address', 'reason', 'source', 'expires_at', 'blocked_by'])]
class BlockedIp extends Model
{
    /**
     * Blocks in force right now.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_hit_at' => 'datetime',
        ];
    }
}
