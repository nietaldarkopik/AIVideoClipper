<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'video_id' => $this->video_id,
            'clip_candidate_id' => $this->clip_candidate_id,
            'template' => TemplateResource::make($this->whenLoaded('template')),
            'template_version_id' => $this->template_version_id,
            'title' => $this->title,
            'caption' => $this->caption,
            'hashtags' => $this->hashtags ?? [],
            'start_time' => (float) $this->start_time,
            'end_time' => (float) $this->end_time,
            'duration' => (float) $this->duration,
            'speed' => (float) $this->speed,
            'volume' => (float) $this->volume,
            'aspect_ratio' => $this->aspect_ratio,
            'segments' => $this->segments,
            'crop_config' => $this->crop_config,
            'scenes' => $this->scenes,
            'subtitle_language' => $this->subtitle_language,
            'subtitles_enabled' => $this->subtitles_enabled,
            'subtitle_config' => $this->subtitle_config,
            'custom_subtitle_format' => $this->custom_subtitle_path
                ? strtolower(pathinfo($this->custom_subtitle_path, PATHINFO_EXTENSION))
                : null,
            'layer_overrides' => $this->layer_overrides,
            'reaction_layout' => $this->reaction_layout,
            'reaction_script' => $this->reaction_script,
            'reaction_tone' => $this->reaction_tone,
            'intro_enabled' => (bool) $this->intro_enabled,
            'outro_enabled' => (bool) $this->outro_enabled,
            'intro_voice' => $this->intro_voice,
            'reference_url' => $this->reference_url,
            'status' => $this->status,
            'progress' => $this->progress,
            'failure_reason' => $this->failure_reason,
            'url' => Media::url($this->output_path),
            'thumbnail_url' => Media::url($this->thumbnail_path),
            'srt_url' => $this->whenLoaded('subtitle', fn () => Media::url($this->subtitle?->srt_path)),
            'output_size_bytes' => $this->output_size_bytes,
            'rendered_at' => $this->rendered_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
