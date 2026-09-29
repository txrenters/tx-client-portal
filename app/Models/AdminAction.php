<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a member of staff did in the admin area, kept so it can be
 * answered for afterwards.
 *
 * @property int $id
 * @property ?int $admin_id
 * @property string $admin_email
 * @property ?int $user_id The person it was done to, when it was done to someone.
 * @property ?string $user_email
 * @property string $action
 * @property string $summary
 * @property ?array<string, mixed> $details
 * @property Carbon $created_at
 */
#[Fillable(['admin_id', 'admin_email', 'user_id', 'user_email', 'action', 'summary', 'details'])]
class AdminAction extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
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
        return [
            'details' => 'array',
        ];
    }
}
