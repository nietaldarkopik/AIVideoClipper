<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClipCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'video_id' => $this->video_id,
            'start_time' => (float) $this->start_time,
            'end_time' => (float) $this->end_time,
            'duration' => (float) $this->duration,
            'scores' => [
                'overall' => $this->overall_score,
                'engagement' => $this->engagement_score,
                'hook' => $this->hook_score,
                'story' => $this->story_score,
                'emotional' => $this->emotional_score,
                'information' => $this->information_score,
                'viral_potential' => $this->viral_potential,
            ],
            'hook_text' => $this->hook_text,
            'moment_type' => $this->moment_type,
            'reasons' => $this->reasons,
            'explanation' => $this->explanation,
            'suggested_title' => $this->suggested_title,
            'suggested_caption' => $this->suggested_caption,
            'suggested_hashtags' => $this->suggested_hashtags,
            'status' => $this->status,
            'clip_id' => $this->whenLoaded('clip', fn () => $this->clip?->id),
        ];
    }
}
