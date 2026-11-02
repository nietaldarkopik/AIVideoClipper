<?php

namespace App\Http\Resources;

use App\Models\AssistantAttachment;
use App\Models\AssistantMessage;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssistantConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
            'messages' => $this->when($this->relationLoaded('messages'), fn () => $this->messages->map(fn (AssistantMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->role === AssistantMessage::ROLE_TOOL ? mb_substr((string) $m->content, 0, 1500) : $m->content,
                'tool_calls' => $m->tool_calls,
                'tool_call_id' => $m->tool_call_id,
                'tool_name' => $m->tool_name,
                'attachments' => $m->attachments->map(fn (AssistantAttachment $a) => $this->attachment($a))->values(),
                'created_at' => $m->created_at,
            ])->values()),
            'actions' => $this->when($this->relationLoaded('actions'), fn () => $this->actions->map(fn ($a) => [
                'id' => $a->id,
                'tool_call_id' => $a->tool_call_id,
                'tool' => $a->tool,
                'risk' => $a->risk,
                'arguments' => $a->arguments,
                'summary' => $a->summary,
                'status' => $a->status,
                'result' => $a->result,
                'decided_at' => $a->decided_at,
            ])->values()),
        ];
    }

    public static function attachment(AssistantAttachment $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->original_name,
            'mime' => $a->mime,
            'size_bytes' => $a->size_bytes,
            'kind' => $a->kind,
            'readable' => $a->kind === AssistantAttachment::KIND_IMAGE || $a->extracted_text !== null,
            'note' => $a->note,
            'url' => $a->kind === AssistantAttachment::KIND_IMAGE ? Media::url($a->path) : null,
        ];
    }
}
