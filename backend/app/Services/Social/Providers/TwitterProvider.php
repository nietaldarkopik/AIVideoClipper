<?php

namespace App\Services\Social\Providers;

use App\Models\SocialPost;

class TwitterProvider extends AbstractMockSocialProvider
{
    public function platform(): string
    {
        return 'twitter';
    }

    public function label(): string
    {
        return 'X';
    }

    protected function buildPostUrl(SocialPost $post, string $externalId): string
    {
        $handle = ltrim($post->socialAccount->username ?? 'user', '@');

        return "https://x.com/{$handle}/status/{$externalId}";
    }
}
