<?php

namespace App\Services\Trending\Providers;

class NineRouterSearchFacebookTrendingProvider extends AbstractNineRouterSearchTrendingProvider
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    protected function domainFilter(): string
    {
        return 'facebook.com';
    }
}
