<?php

namespace App\Http\Resources\Research;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_key' => $this->source_key,
            'title' => $this->title,
            'url' => $this->url,
            'summary' => $this->summary,
            'author' => $this->author,
            'published_at' => $this->published_at,
            'discovered_at' => $this->discovered_at,
            'engagement' => $this->engagement ?? [],
            'source_score' => $this->source_score,
            'topic_key' => $this->topic_key,
        ];
    }
}
