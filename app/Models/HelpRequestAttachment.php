<?php

namespace App\Models;

use Database\Factories\HelpRequestAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A screenshot or file a client sent with a help request, so staff can see
 * what they saw.
 *
 * @property int $id
 * @property int $help_request_id
 * @property string $path Where it sits on the private local disk.
 * @property string $name The file's name as the client picked it.
 * @property string $mime_type
 * @property int $size In bytes.
 */
#[Fillable(['help_request_id', 'path', 'name', 'mime_type', 'size'])]
class HelpRequestAttachment extends Model
{
    /** @use HasFactory<HelpRequestAttachmentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<HelpRequest, $this>
     */
    public function helpRequest(): BelongsTo
    {
        return $this->belongsTo(HelpRequest::class);
    }

    /**
     * Whether a browser can show it as a picture. HEIC only draws in Safari,
     * so it is offered as a file instead.
     */
    public function isImage(): bool
    {
        return in_array($this->mime_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }
}
