<?php

namespace App\Http\Resources;

use App\Models\CartoonScene;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartoonVideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $scenes = $this->whenLoaded('scenes');
        $sceneCount = $this->relationLoaded('scenes') ? $this->scenes->count() : null;

        return [
            'id' => $this->id,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'title' => $this->title,
            'topic' => $this->topic,
            'character_description' => $this->character_description,
            'style' => $this->style,
            'voice' => $this->voice,
            'motion_provider' => $this->motion_provider,
            'status' => $this->status,
            'progress' => $this->progress,
            'message' => $this->message,
            'failure_reason' => $this->failure_reason,
            'duration_seconds' => $this->duration_seconds,
            'url' => Media::url($this->output_path),
            'thumbnail_url' => Media::url($this->thumbnail_path),
            'project_id' => $this->project_id,
            'clip_id' => $this->clip_id,
            'estimated_cost_usd' => $sceneCount === null ? null : $this->estimateCost($sceneCount),
            'scenes' => $this->when($this->relationLoaded('scenes'), fn () => $this->scenes->map(fn (CartoonScene $s) => [
                'id' => $s->id,
                'position' => $s->position,
                'narration' => $s->narration,
                'visual_prompt' => $s->visual_prompt,
                'motion_prompt' => $s->motion_prompt,
                'status' => $s->status,
                'error' => $s->error,
                'audio_duration' => $s->audio_duration,
                'image_provider' => $s->image_provider,
                'clip_provider' => $s->clip_provider,
                'image_url' => Media::url($s->image_path),
                'clip_url' => Media::url($s->clip_path),
            ])->values()),
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Rough spend for rendering every scene that isn't done yet. Only AI video
     * providers cost anything per scene; camera_motion is free.
     */
    private function estimateCost(int $sceneCount): float
    {
        $remaining = $this->scenes->where('status', '!=', CartoonScene::STATUS_DONE)->count();
        $perScene = (float) config('cartoon.estimate.image', 0) + ($this->motion_provider === 'camera_motion'
            ? 0.0
            : (float) config('cartoon.estimate.video_per_scene', 0));

        return round($remaining * $perScene, 2);
    }
}
