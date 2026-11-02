<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantAttachment extends Model
{
    public const KIND_IMAGE = 'image';

    public const KIND_DOCUMENT = 'document';

    public const KIND_SPREADSHEET = 'spreadsheet';

    public const KIND_TEXT = 'text';

    public const KIND_UNSUPPORTED = 'unsupported';

    protected $fillable = [
        'user_id', 'assistant_message_id', 'original_name', 'mime', 'size_bytes', 'path', 'kind', 'extracted_text', 'note',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'assistant_message_id');
    }
}
