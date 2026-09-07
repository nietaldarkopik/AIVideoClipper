<?php

namespace App\Http\Resources\Research;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentIdeaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content_channel_id' => $this->content_channel_id,
            'channel' => ContentChannelResource::make($this->whenLoaded('channel')),
            'research_run_id' => $this->research_run_id,
            'research_date' => $this->research_date?->toDateString(),

            'topic' => $this->topic,
            'title' => $this->title,
            'alternative_titles' => $this->alternative_titles ?? [],
            'short_description' => $this->short_description,
            'content_angle' => $this->content_angle,
            'why_this_topic' => $this->why_this_topic,
            'target_audience' => $this->target_audience,
            'keywords' => $this->keywords ?? [],
            'source_summary' => $this->source_summary,

            'scores' => [
                'trend' => $this->trend_score,
                'relevance' => $this->relevance_score,
                'originality' => $this->originality_score,
                'freshness' => $this->freshness_score,
                'engagement' => $this->engagement_score,
                'cross_source' => $this->cross_source_score,
                'priority' => $this->priority_score,
            ],
            // Kept flat as well as nested: the list view sorts and filters on these two
            // directly, and reading them out of the nested object in every table cell
            // was needless indirection.
            'priority_score' => $this->priority_score,
            'trend_score' => $this->trend_score,

            'suggested_content_type' => $this->suggested_content_type,
            'suggested_format' => $this->suggested_format,
            'status' => $this->status,
            'notes' => $this->notes,
            'selected_at' => $this->selected_at,
            'sources' => ContentIdeaSourceResource::collection($this->whenLoaded('sources')),
            'sources_count' => $this->whenCounted('sources'),
            'created_at' => $this->created_at,
        ];
    }
}
