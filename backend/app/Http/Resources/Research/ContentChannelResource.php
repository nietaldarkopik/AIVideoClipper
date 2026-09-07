<?php

namespace App\Http\Resources\Research;

use App\Models\ContentChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ContentChannel $channel */
        $channel = $this->resource;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description,
            'language' => $this->language,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,

            'platform_id' => $this->platform_id,
            'platform' => PlatformResource::make($this->whenLoaded('platform')),

            'niche' => $this->niche,
            'sub_niches' => $this->sub_niches ?? [],
            'keywords' => $this->keywords ?? [],
            'excluded_keywords' => $this->excluded_keywords ?? [],
            'target_audience' => $this->target_audience,

            'content_style' => $this->content_style ?? [],
            'content_types' => $this->content_types ?? [],
            'content_formats' => $this->content_formats ?? [],
            'tone' => $this->tone,
            'hook_styles' => $this->hook_styles ?? [],

            'scheduler_enabled' => $this->scheduler_enabled,
            'research_frequency' => $this->research_frequency,
            // The stored times AND the expanded schedule: for every_n_hours the stored
            // value is empty, so the UI would otherwise show "no schedule" for a channel
            // that in fact runs every 6 hours.
            'research_times' => $this->research_times ?? [],
            'schedule_times' => $channel->scheduleTimes(),
            'interval_hours' => $this->interval_hours,
            'ideas_per_run' => $this->ideas_per_run,
            'min_relevance_score' => $this->min_relevance_score,
            'min_trend_score' => $this->min_trend_score,
            'scoring_weights' => $channel->effectiveScoringWeights(),

            'last_research_at' => $this->last_research_at,
            'next_research_at' => $this->next_research_at,

            'research_sources' => ResearchSourceResource::collection($this->whenLoaded('researchSources')),
            'ideas_count' => $this->whenCounted('contentIdeas'),
            'runs_count' => $this->whenCounted('researchRuns'),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
