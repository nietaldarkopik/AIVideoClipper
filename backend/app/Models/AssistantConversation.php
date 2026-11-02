<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantConversation extends Model
{
    public const STATUS_IDLE = 'idle';

    public const STATUS_RUNNING = 'running';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['user_id', 'title', 'status', 'failure_reason', 'cancel_requested', 'last_message_at'];

    protected function casts(): array
    {
        return ['cancel_requested' => 'boolean', 'last_message_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class)->orderBy('id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AssistantAction::class)->orderBy('id');
    }
}
