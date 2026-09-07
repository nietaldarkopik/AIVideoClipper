<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'video' => VideoResource::make($this->whenLoaded('videos', fn () => $this->videos->last())),
            // Every video in the project, not just the latest — the clip
            // editor's "additional video clips" picker reads this to offer any
            // of them (see RenderClipJob::renderAdditionalVideoClips()). The
            // controller already eager-loads the full relation for this
            // resource, so exposing it here costs no extra query.
            'videos' => VideoResource::collection($this->whenLoaded('videos')),
            'clip_candidates_count' => $this->whenCounted('clipCandidates'),
            'clips_count' => $this->whenCounted('clips'),
            'clip_candidates' => ClipCandidateResource::collection($this->whenLoaded('clipCandidates')),
            'clips' => ClipResource::collection($this->whenLoaded('clips')),
            'last_edited_at' => $this->last_edited_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
