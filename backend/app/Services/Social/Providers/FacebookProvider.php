<?php

namespace App\Services\Social\Providers;

use App\Models\SocialPost;

class FacebookProvider extends AbstractMockSocialProvider
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    protected function buildPostUrl(SocialPost $post, string $externalId): string
    {
        return "https://www.facebook.com/reel/{$externalId}";
    }
}
