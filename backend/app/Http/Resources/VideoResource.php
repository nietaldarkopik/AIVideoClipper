<?php

namespace App\Http\Resources;

use App\Services\Video\FFmpegService;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'source_type' => $this->source_type,
            'source_url' => $this->source_url,
            'title' => $this->title,
            'original_filename' => $this->original_filename,
            'url' => Media::url($this->disk_path),
            'thumbnail_url' => Media::url($this->thumbnail_path),
            // Null for a video imported before this feature, or whose strip/
            // waveform generation failed/had no audio — TimelineTrack falls back
            // to a plain bar. tile_count is always the same fixed constant
            // (FFmpegService::THUMBNAIL_STRIP_TILE_COUNT) but sent explicitly so
            // the frontend's slice math never hardcodes it.
            'thumbnail_strip_url' => Media::url($this->thumbnail_strip_path),
            'thumbnail_strip_tile_count' => FFmpegService::THUMBNAIL_STRIP_TILE_COUNT,
            'waveform_url' => Media::url($this->waveform_path),
            'duration_seconds' => $this->duration_seconds,
            'width' => $this->width,
            'height' => $this->height,
            'resolution' => $this->resolutionLabel(),
            'file_size_bytes' => $this->file_size_bytes,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'has_transcript' => $this->whenLoaded('transcript', fn () => (bool) $this->transcript, fn () => null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
