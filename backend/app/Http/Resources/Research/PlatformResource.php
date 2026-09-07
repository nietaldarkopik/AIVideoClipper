<?php

namespace App\Http\Resources\Research;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type,
            'default_strategy' => $this->default_strategy ?? [],
            'enabled' => $this->enabled,
            'sort_order' => $this->sort_order,
        ];
    }
}
