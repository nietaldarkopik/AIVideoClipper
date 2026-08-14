<?php

namespace App\Services\AI\Contracts;

use App\Models\Clip;

interface SocialMetadataProvider
{
    /**
     * Generate platform-tailored title/caption/description/hashtags/CTA for a clip.
     *
     * @param  string[]  $platforms  e.g. ['tiktok', 'instagram', 'youtube', 'x', 'linkedin']
     * @return array<string, array{title?: string, caption?: string, description?: string, hashtags: string[], cta?: string, first_comment?: string}>
     */
    public function generateMetadata(Clip $clip, array $platforms): array;
}
