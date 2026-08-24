<?php

namespace App\Services\Trending\Providers;

class FacebookTrendingProvider extends AbstractMockTrendingProvider
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    protected function seedItems(): array
    {
        return [
            [
                'external_id' => '10209653193067040',
                'title' => 'Chewbacca Mask Lady',
                'source_url' => 'https://www.facebook.com/candaceSpayne/videos/10209653193067040/',
                'author_name' => 'Candace Payne',
            ],
        ];
    }
}
