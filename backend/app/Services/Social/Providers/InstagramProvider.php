<?php

namespace App\Services\Social\Providers;

use App\Models\SocialPost;

class InstagramProvider extends AbstractMockSocialProvider
{
    public function platform(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    protected function buildPostUrl(SocialPost $post, string $externalId): string
    {
        return "https://www.instagram.com/reel/{$externalId}/";
    }
}
