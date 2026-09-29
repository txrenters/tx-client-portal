<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sign-in or failed attempt, for the admin sign-in log.
 *
 * @property int $id
 * @property ?int $user_id
 * @property ?string $email The address that was tried.
 * @property string $event signed_in, failed, failed_two_factor, locked_out or blocked.
 * @property string $channel web or app.
 * @property ?string $method password, passkey, remembered or two_factor.
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'email', 'event', 'channel', 'method', 'ip_address', 'user_agent'])]
class SignInEvent extends Model
{
    use Prunable;

    /** Only when it happened; the rows are never edited. */
    public const UPDATED_AT = null;

    /** How long the log is kept. */
    public const KEEP_DAYS = 180;

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
