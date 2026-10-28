<?php

namespace App\Models;

use App\Enums\ClientRole;
use Database\Factories\ClientAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Links a client login to the Propertyware tenant or owner record it represents.
 *
 * @property ClientRole $role
 */
#[Fillable(['role', 'propertyware_id', 'label'])]
#[Hidden(['propertyware_id'])]
class ClientAccount extends Model
{
    /** @use HasFactory<ClientAccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<RentReminderNotice, $this>
     */
    public function rentReminders(): HasMany
    {
        return $this->hasMany(RentReminderNotice::class);
    }

    protected function casts(): array
    {
        return [
            'role' => ClientRole::class,
        ];
    }
}
