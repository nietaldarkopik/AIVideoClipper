<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiRequestLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'capability' => $this->capability,
            'provider' => $this->provider,
            'model' => $this->model,
            'project' => $this->whenLoaded('project', fn () => $this->project ? ['id' => $this->project->id, 'title' => $this->project->title] : null),
            'video' => $this->whenLoaded('video', fn () => $this->video ? ['id' => $this->video->id, 'title' => $this->video->title] : null),
            'clip' => $this->whenLoaded('clip', fn () => $this->clip ? ['id' => $this->clip->id, 'title' => $this->clip->title] : null),
            'prompt' => $this->prompt,
            'response' => $this->response,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at,
        ];
    }
}
