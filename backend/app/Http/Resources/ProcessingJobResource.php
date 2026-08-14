<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcessingJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'video_id' => $this->video_id,
            'clip_id' => $this->clip_id,
            'type' => $this->type,
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'error' => $this->error,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
        ];
    }
}
