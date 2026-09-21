<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FacebookMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'social_account_id' => $this->social_account_id,
            'page_name' => $this->whenLoaded('socialAccount', fn () => $this->socialAccount->account_name),
            'sender_name' => $this->sender_name,
            'message' => $this->message,
            'sent_at' => $this->sent_at,
            'status' => $this->status,
            'content_idea_id' => $this->content_idea_id,
            'created_at' => $this->created_at,
        ];
    }
}
