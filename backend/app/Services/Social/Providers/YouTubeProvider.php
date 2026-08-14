<?php

namespace App\Services\Social\Providers;

use App\Models\SocialPost;

class YouTubeProvider extends AbstractMockSocialProvider
{
    public function platform(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    protected function buildPostUrl(SocialPost $post, string $externalId): string
    {
        return "https://www.youtube.com/shorts/{$externalId}";
    }
}
