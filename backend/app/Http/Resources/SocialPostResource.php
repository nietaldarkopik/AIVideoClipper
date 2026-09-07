<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clip_id' => $this->clip_id,
            'clip' => $this->whenLoaded('clip', fn () => [
                'id' => $this->clip->id,
                'title' => $this->clip->title,
                'thumbnail_url' => Media::url($this->clip->thumbnail_path),
                'url' => Media::url($this->clip->output_path),
                'duration' => (float) $this->clip->duration,
                'project_id' => $this->clip->project_id,
                'project_title' => $this->clip->project?->title,
            ]),
            'platform' => $this->platform,
            'social_account' => SocialAccountResource::make($this->whenLoaded('socialAccount')),
            'title' => $this->title,
            'caption' => $this->caption,
            'hashtags' => $this->hashtags ?? [],
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'published_at' => $this->published_at,
            'post_url' => $this->post_url,
            'error_message' => $this->error_message,
            'retry_count' => $this->retry_count,
            'thumbnail_status' => $this->thumbnail_status,
            'thumbnail_uploaded_at' => $this->thumbnail_uploaded_at,
            'thumbnail_error' => $this->thumbnail_error,
            'metrics' => $this->metrics,
            'metrics_synced_at' => $this->metrics_synced_at,
            'created_at' => $this->created_at,
        ];
    }
}
