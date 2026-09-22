<?php

namespace App\Models;

use App\Enums\ClientRole;
use Database\Factories\ClientInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[Fillable(['email', 'name', 'role', 'propertyware_id', 'token_hash', 'expires_at'])]
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
        $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    protected function casts(): array
    {
        return [
            'role' => ClientRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
