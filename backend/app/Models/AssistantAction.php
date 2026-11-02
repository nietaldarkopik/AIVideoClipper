<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tool call the assistant wants to make that changes something or reaches outside
 * the app. It sits here as `pending` until the user approves or rejects it — the model
 * never gets to run these on its own, whatever it was told in a prompt or an attachment.
 */
class AssistantAction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'assistant_conversation_id', 'assistant_message_id', 'tool_call_id', 'tool', 'risk',
        'arguments', 'summary', 'status', 'result', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'result' => 'array', 'decided_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'assistant_conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AssistantMessage::class, 'assistant_message_id');
    }
}
