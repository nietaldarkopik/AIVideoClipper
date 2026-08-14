<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubtitleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clip_id' => $this->clip_id,
            'language' => $this->language,
            'segments' => $this->segments,
            'srt_url' => Media::url($this->srt_path),
            'ass_url' => Media::url($this->ass_path),
        ];
    }
}
