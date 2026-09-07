<?php

namespace App\Http\Resources\Research;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentIdeaSourceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_key' => $this->source_key,
            'source_title' => $this->source_title,
            'source_url' => $this->source_url,
            'extracted_summary' => $this->extracted_summary,
            'engagement_metrics' => $this->engagement_metrics ?? [],
            'source_score' => $this->source_score,
            'published_at' => $this->published_at,
            'discovered_at' => $this->discovered_at,
        ];
    }
}
