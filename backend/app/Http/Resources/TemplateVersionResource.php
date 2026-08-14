<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_id' => $this->template_id,
            'version_number' => $this->version_number,
            'label' => $this->label ?? ('v' . $this->version_number),
            'is_published' => $this->is_published,
            'config' => $this->config,
            'created_at' => $this->created_at,
        ];
    }
}
