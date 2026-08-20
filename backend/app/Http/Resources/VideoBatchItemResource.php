<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoBatchItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'source_url' => $this->source_url,
            'project_id' => $this->project_id,
            'project_title' => $this->whenLoaded('project', fn () => $this->project?->title),
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'failure_reason' => $this->failure_reason,
            'clips_generated' => $this->clips_generated,
            'posts_published' => $this->posts_published,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
