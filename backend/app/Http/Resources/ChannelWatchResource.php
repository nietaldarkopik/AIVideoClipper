<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelWatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'channel_id' => $this->channel_id,
            'channel_title' => $this->channel_title,
            'channel_url' => $this->channel_url,
            'thumbnail_url' => $this->thumbnail_url,
            'is_active' => $this->is_active,
            'settings' => $this->settings,
            'last_video_id' => $this->last_video_id,
            'last_video_published_at' => $this->last_video_published_at,
            'last_checked_at' => $this->last_checked_at,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
        ];
    }
}
