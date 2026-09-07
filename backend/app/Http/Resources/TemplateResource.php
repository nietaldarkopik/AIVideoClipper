<?php

namespace App\Http\Resources;

use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'thumbnail_url' => Media::url($this->thumbnail_path),
            'preview_url' => $this->preview_path ? Media::url($this->preview_path) : null,
            'preview_status' => $this->preview_status,
            'category' => TemplateCategoryResource::make($this->whenLoaded('category')),
            'aspect_ratio' => $this->aspect_ratio,
            'resolution' => ['width' => $this->resolution_width, 'height' => $this->resolution_height],
            'status' => $this->status,
            'is_system' => $this->is_system,
            'current_version' => TemplateVersionResource::make($this->whenLoaded('currentVersion')),
            'versions' => TemplateVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
