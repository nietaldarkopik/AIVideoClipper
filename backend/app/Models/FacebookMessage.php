<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookMessage extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_CONVERTED = 'converted';

    protected $fillable = [
        'social_account_id', 'content_idea_id', 'external_conversation_id',
        'external_message_id', 'sender_name', 'sender_psid', 'message',
        'sent_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function contentIdea(): BelongsTo
    {
        return $this->belongsTo(ContentIdea::class);
    }
}
