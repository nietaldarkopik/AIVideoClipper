<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'settings' => $this->settings,
            'total_items' => $this->total_items,
            'completed_items' => $this->completed_items,
            'failed_items' => $this->failed_items,
            'items' => VideoBatchItemResource::collection($this->whenLoaded('items')),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
