<?php

namespace App\Models;

use App\Enums\HelpRequestStatus;
use App\Enums\HelpTopic;
use Database\Factories\HelpRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A client asking for help with the portal or the apps themselves.
 *
 * @property int $id
 * @property int $user_id
 * @property HelpTopic $topic
 * @property string $message
 * @property string $platform web, ios or android.
 * @property ?string $app_version
 * @property ?string $user_agent
 * @property HelpRequestStatus $status
 * @property ?string $staff_note What staff did about it, shown to the client once resolved.
 * @property ?Carbon $resolved_at
 * @property ?int $resolved_by
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'topic', 'message', 'platform', 'app_version', 'user_agent', 'status', 'staff_note', 'resolved_at', 'resolved_by'])]
class HelpRequest extends Model
{
    /** @use HasFactory<HelpRequestFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return HasMany<HelpRequestAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(HelpRequestAttachment::class);
    }

    protected function casts(): array
    {
        return [
            'topic' => HelpTopic::class,
            'status' => HelpRequestStatus::class,
            'resolved_at' => 'datetime',
        ];
    }
}
