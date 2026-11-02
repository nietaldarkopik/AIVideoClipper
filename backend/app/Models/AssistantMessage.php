<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    protected $fillable = [
        'assistant_conversation_id', 'role', 'content', 'tool_calls', 'tool_call_id', 'tool_name', 'meta',
    ];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'meta' => 'array'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssistantAttachment::class);
    }
}
