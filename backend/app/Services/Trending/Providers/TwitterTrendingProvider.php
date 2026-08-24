<?php

namespace App\Services\Trending\Providers;

class TwitterTrendingProvider extends AbstractMockTrendingProvider
{
    public function platform(): string
    {
        return 'twitter';
    }

    public function label(): string
    {
        return 'X (Twitter)';
    }

    protected function seedItems(): array
    {
        return [
            [
                'external_id' => '1256648835272605697',
                'title' => 'Jungkook — singing clip',
                'source_url' => 'https://twitter.com/BTS_twt/status/1256648835272605697',
                'author_name' => '@BTS_twt',
            ],
        ];
    }
}
