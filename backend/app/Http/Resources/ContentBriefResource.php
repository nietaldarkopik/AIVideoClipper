<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentBriefResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'region_code' => $this->region_code,
            'source_platform' => $this->source_platform,
            'source_trending_title' => $this->source_trending_title,
            'source_trending_url' => $this->source_trending_url,
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'failure_reason' => $this->failure_reason,
            'sources' => $this->sources ?? [],
            'candidate_videos' => $this->candidate_videos ?? [],
            'narrative_title' => $this->narrative_title,
            'narrative_hook' => $this->narrative_hook,
            'narrative_sections' => $this->narrative_sections ?? [],
            'narrative_full_script' => $this->narrative_full_script,
            'narrative_suggested_description' => $this->narrative_suggested_description,
            'narrative_suggested_hashtags' => $this->narrative_suggested_hashtags ?? [],
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }
}
