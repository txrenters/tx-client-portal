<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far a login has read in one of their hub conversations.
 */
#[Fillable(['user_id', 'thread_id', 'last_read_message_id'])]
class ConversationRead extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'thread_id' => 'integer',
            'last_read_message_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
