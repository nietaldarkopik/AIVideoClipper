<?php

namespace App\Services\Trending\Providers;

class NineRouterSearchTikTokTrendingProvider extends AbstractNineRouterSearchTrendingProvider
{
    public function platform(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    protected function domainFilter(): string
    {
        return 'tiktok.com';
    }
}
