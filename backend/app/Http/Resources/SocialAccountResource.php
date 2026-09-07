<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'account_name' => $this->account_name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'status' => $this->status,
            'token_status' => $this->token_expires_at && $this->token_expires_at->isPast() ? 'expired' : 'valid',
            'permissions' => $this->permissions ?? [],
            'auto_publish_enabled' => $this->auto_publish_enabled,
            'default_cover_template_id' => $this->default_cover_template_id,
            'default_cover_template' => CoverTemplateResource::make($this->whenLoaded('defaultCoverTemplate')),
            'last_synced_at' => $this->last_synced_at,
            'created_at' => $this->created_at,
        ];
    }
}
