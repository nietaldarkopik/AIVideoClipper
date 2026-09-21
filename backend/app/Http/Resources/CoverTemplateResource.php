<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CoverTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail_url' => Media::url($this->thumbnail_path),
            'aspect_ratio' => $this->aspect_ratio,
            'config' => $this->config,
            'status' => $this->status,
            'is_system' => $this->is_system,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
