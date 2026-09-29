<?php

namespace App\Models;

use Database\Factories\PushTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One phone that has asked to be rung, identified by the Expo push token its
 * app minted. The Expo push service routes on this token alone, so it is all
 * the portal needs to reach the device through APNs or FCM.
 *
 * @property int $user_id
 * @property string $token The ExponentPushToken[...] the app registered.
 * @property string $platform "ios" or "android".
 * @property ?string $device_name What the phone calls itself, to tell two devices apart.
 * @property ?Carbon $last_pushed_at
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'token', 'platform', 'device_name', 'last_pushed_at'])]
class PushToken extends Model
{
    /** @use HasFactory<PushTokenFactory> */
    use HasFactory;

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
            'last_pushed_at' => 'datetime',
        ];
    }
}
