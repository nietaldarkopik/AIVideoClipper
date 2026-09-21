<?php

namespace App\Http\Resources\Research;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'platform_key' => $this->platform_key,
            'defaults' => $this->defaults ?? [],
            'sort_order' => $this->sort_order,
        ];
    }
}
