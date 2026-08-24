<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrendingItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'platform' => $this->platform,
            'external_id' => $this->external_id,
            'title' => $this->title,
            'source_url' => $this->source_url,
            'thumbnail_url' => $this->thumbnail_url,
            'author_name' => $this->author_name,
            'view_count' => $this->view_count,
            'like_count' => $this->like_count,
            'comment_count' => $this->comment_count,
            'published_at' => $this->published_at,
            'is_mock' => $this->is_mock,
        ];
    }
}
