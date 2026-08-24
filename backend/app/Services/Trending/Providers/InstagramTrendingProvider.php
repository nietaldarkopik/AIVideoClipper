<?php

namespace App\Services\Trending\Providers;

class InstagramTrendingProvider extends AbstractMockTrendingProvider
{
    public function platform(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    protected function seedItems(): array
    {
        return [
            [
                'external_id' => 'DKw2J6TMZd7',
                'title' => 'Mastercard "Priceless" — Lionel Messi',
                'source_url' => 'https://www.instagram.com/reel/DKw2J6TMZd7/',
                'author_name' => '@mastercard',
            ],
        ];
    }
}
