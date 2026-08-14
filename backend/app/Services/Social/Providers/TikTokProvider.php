<?php

namespace App\Services\Social\Providers;

use App\Models\SocialPost;

class TikTokProvider extends AbstractMockSocialProvider
{
    public function platform(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    protected function buildPostUrl(SocialPost $post, string $externalId): string
    {
        $handle = ltrim($post->socialAccount->username ?? 'user', '@');

        return "https://www.tiktok.com/@{$handle}/video/{$externalId}";
    }
}
