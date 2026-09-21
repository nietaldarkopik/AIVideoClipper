<?php

namespace App\Http\Resources\Research;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResearchRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content_channel_id' => $this->content_channel_id,
            'channel' => ContentChannelResource::make($this->whenLoaded('channel')),
            'trigger' => $this->trigger,
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'topics_found' => $this->topics_found,
            'results_collected' => $this->results_collected,
            'ideas_generated' => $this->ideas_generated,
            'duplicates_skipped' => $this->duplicates_skipped,
            'providers_used' => $this->providers_used ?? [],
            // Includes both hard failures and skipped-with-a-reason sources, each
            // carrying its own message, so a PARTIAL run explains itself in the UI.
            'providers_failed' => $this->providers_failed ?? [],
            'error_message' => $this->error_message,
            'duration_ms' => $this->duration_ms,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
            'results' => ResearchResultResource::collection($this->whenLoaded('results')),
            'ideas' => ContentIdeaResource::collection($this->whenLoaded('ideas')),
        ];
    }
}
