<?php

namespace App\Models;

use App\Enums\ReferralStatus;
use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A property owner a client referred to TexasRenters.com, for staff to follow up.
 *
 * @property string $referrer_name Who referred them, kept even if their login is removed.
 * @property string $referrer_role
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $phone
 * @property string $property_address
 * @property ?string $property_details
 * @property string $source How the owner heard about us: always "Referral" from the portal.
 * @property ReferralStatus $status
 * @property ?Carbon $status_changed_at
 * @property ?Carbon $lead_sent_at When the leads webhook accepted it.
 * @property ?string $lead_error Why the leads webhook last refused it.
 * @property Carbon $created_at
 */
#[Fillable([
    'user_id', 'client_account_id', 'referrer_name', 'referrer_role', 'first_name', 'last_name',
    'email', 'phone', 'property_address', 'property_details', 'source', 'status', 'status_changed_at', 'lead_sent_at', 'lead_error',
])]
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ClientAccount, $this>
     */
    public function clientAccount(): BelongsTo
    {
        return $this->belongsTo(ClientAccount::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ReferralStatus::class,
            'status_changed_at' => 'datetime',
            'lead_sent_at' => 'datetime',
        ];
    }
}
