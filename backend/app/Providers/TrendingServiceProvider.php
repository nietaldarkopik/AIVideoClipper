<?php

namespace App\Providers;

use App\Services\Trending\Providers\FacebookTrendingProvider;
use App\Services\Trending\Providers\InstagramTrendingProvider;
use App\Services\Trending\Providers\MockYouTubeTrendingProvider;
use App\Services\Trending\Providers\NineRouterSearchFacebookTrendingProvider;
use App\Services\Trending\Providers\NineRouterSearchInstagramTrendingProvider;
use App\Services\Trending\Providers\NineRouterSearchTikTokTrendingProvider;
use App\Services\Trending\Providers\NineRouterSearchTwitterTrendingProvider;
use App\Services\Trending\Providers\TikTokTrendingProvider;
use App\Services\Trending\Providers\TwitterTrendingProvider;
use App\Services\Trending\Providers\YouTubeTrendingProvider;
use App\Services\Trending\TrendingProviderManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Facebook/TikTok/Instagram/Twitter still have no official trending API, so each
 * defaults to mock — but can approximate one via a 9Router web search
 * (nine_router_search, see AbstractNineRouterSearchTrendingProvider) instead of
 * staying permanently mock like before this feature. YouTube defaults to mock too
 * (config('services.trending.*'), env-driven, same throw-on-typo behavior as
 * AIServiceProvider) so the page works with zero API keys — set
 * TRENDING_YOUTUBE_PROVIDER=youtube_api + YOUTUBE_API_KEY to switch it to the real
 * Data API.
 */
class TrendingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TrendingProviderManager::class, function () {
            $youtubeProvider = match ($provider = config('services.trending.youtube_provider', 'mock')) {
                'mock' => MockYouTubeTrendingProvider::class,
                'youtube_api' => YouTubeTrendingProvider::class,
                default => throw new InvalidArgumentException("Unknown TRENDING_YOUTUBE_PROVIDER [{$provider}]. Valid values: mock, youtube_api."),
            };

            $tiktokProvider = match ($provider = config('services.trending.tiktok_provider', 'mock')) {
                'mock' => TikTokTrendingProvider::class,
                'nine_router_search' => NineRouterSearchTikTokTrendingProvider::class,
                default => throw new InvalidArgumentException("Unknown TRENDING_TIKTOK_PROVIDER [{$provider}]. Valid values: mock, nine_router_search."),
            };

            $instagramProvider = match ($provider = config('services.trending.instagram_provider', 'mock')) {
                'mock' => InstagramTrendingProvider::class,
                'nine_router_search' => NineRouterSearchInstagramTrendingProvider::class,
                default => throw new InvalidArgumentException("Unknown TRENDING_INSTAGRAM_PROVIDER [{$provider}]. Valid values: mock, nine_router_search."),
            };

            $facebookProvider = match ($provider = config('services.trending.facebook_provider', 'mock')) {
                'mock' => FacebookTrendingProvider::class,
                'nine_router_search' => NineRouterSearchFacebookTrendingProvider::class,
                default => throw new InvalidArgumentException("Unknown TRENDING_FACEBOOK_PROVIDER [{$provider}]. Valid values: mock, nine_router_search."),
            };

            $twitterProvider = match ($provider = config('services.trending.twitter_provider', 'mock')) {
                'mock' => TwitterTrendingProvider::class,
                'nine_router_search' => NineRouterSearchTwitterTrendingProvider::class,
                default => throw new InvalidArgumentException("Unknown TRENDING_TWITTER_PROVIDER [{$provider}]. Valid values: mock, nine_router_search."),
            };

            return new TrendingProviderManager([
                'youtube' => $youtubeProvider,
                'facebook' => $facebookProvider,
                'tiktok' => $tiktokProvider,
                'instagram' => $instagramProvider,
                'twitter' => $twitterProvider,
            ]);
        });
    }
}
