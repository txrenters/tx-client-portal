<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sign of someone probing the portal; see IpGuard for the types.
 *
 * @property int $id
 * @property string $type
 * @property ?string $ip_address
 * @property ?int $user_id
 * @property ?string $path
 * @property ?string $user_agent
 * @property ?array<string, mixed> $details
 * @property Carbon $created_at
 */
#[Fillable(['type', 'ip_address', 'user_id', 'path', 'user_agent', 'details'])]
class SecurityEvent extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    /** How long events are kept. */
    public const KEEP_DAYS = 180;

    /**
     * @return Builder<static>
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

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
