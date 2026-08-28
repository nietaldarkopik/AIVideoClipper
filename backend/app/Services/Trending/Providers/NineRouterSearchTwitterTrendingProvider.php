<?php

namespace App\Services\Trending\Providers;

class NineRouterSearchTwitterTrendingProvider extends AbstractNineRouterSearchTrendingProvider
{
    public function platform(): string
    {
        return 'twitter';
    }

    public function label(): string
    {
        return 'X (Twitter)';
    }

    protected function domainFilter(): string
    {
        return 'x.com,twitter.com';
    }
}
