<?php

namespace App\Models;

use App\Enums\ClientRole;
use Database\Factories\ClientInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property string $name
 * @property ClientRole $role
 * @property ?string $propertyware_id
 * @property string $token_hash
 * @property ?Carbon $expires_at
 * @property ?Carbon $accepted_at
 * @property ?Carbon $cancelled_at Staff withdrew the link before it was used.
 * @property ?int $invited_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[Fillable(['email', 'name', 'role', 'propertyware_id', 'token_hash', 'expires_at', 'invited_by'])]
#[Hidden(['propertyware_id', 'token_hash'])]
class ClientInvitation extends Model
{
    /** @use HasFactory<ClientInvitationFactory> */
    use HasFactory;

    /**
     * Hash a plain invitation token for storage and lookup.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Find a pending, unexpired invitation by its plain token.
     */
    public static function findPending(string $token): ?self
    {
        return static::query()
            ->pending()
            ->where('token_hash', static::hashToken($token))
            ->first();
    }

    /**
     * Scope the query to invitations that can still be accepted.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('cancelled_at')->where('expires_at', '>', now());
    }

    /**
     * Where the invitation stands: pending, accepted, cancelled or expired.
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->cancelled_at !== null => 'cancelled',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    protected function casts(): array
    {
        return [
            'role' => ClientRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
