<?php

namespace App\Services\Trending\Providers;

class NineRouterSearchInstagramTrendingProvider extends AbstractNineRouterSearchTrendingProvider
{
    public function platform(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    protected function domainFilter(): string
    {
        return 'instagram.com';
    }
}
