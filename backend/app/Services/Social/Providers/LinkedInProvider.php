<?php

namespace App\Services\Social\Providers;

use App\Models\SocialPost;

class LinkedInProvider extends AbstractMockSocialProvider
{
    public function platform(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn';
    }

    protected function buildPostUrl(SocialPost $post, string $externalId): string
    {
        return "https://www.linkedin.com/feed/update/urn:li:activity:{$externalId}";
    }
}
