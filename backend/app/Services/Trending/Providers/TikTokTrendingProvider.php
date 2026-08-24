<?php

namespace App\Services\Trending\Providers;

class TikTokTrendingProvider extends AbstractMockTrendingProvider
{
    public function platform(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    protected function seedItems(): array
    {
        return [
            [
                'external_id' => '6862153058223197445',
                'title' => 'Lip sync — "M to the B"',
                'source_url' => 'https://www.tiktok.com/@bellapoarch/video/6862153058223197445',
                'author_name' => '@bellapoarch',
            ],
        ];
    }
}
