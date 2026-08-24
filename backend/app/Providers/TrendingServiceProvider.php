<?php

namespace App\Providers;

use App\Services\Trending\Providers\FacebookTrendingProvider;
use App\Services\Trending\Providers\InstagramTrendingProvider;
use App\Services\Trending\Providers\MockYouTubeTrendingProvider;
use App\Services\Trending\Providers\TikTokTrendingProvider;
use App\Services\Trending\Providers\TwitterTrendingProvider;
use App\Services\Trending\Providers\YouTubeTrendingProvider;
use App\Services\Trending\TrendingProviderManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Facebook/TikTok/Instagram/Twitter have no viable free/ToS-safe trending API, so
 * they're always mock. YouTube defaults to mock too (config('services.trending.*'),
 * env-driven, same throw-on-typo behavior as AIServiceProvider) so the page works
 * with zero API keys — set TRENDING_YOUTUBE_PROVIDER=youtube_api + YOUTUBE_API_KEY
 * to switch it to the real Data API.
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

            return new TrendingProviderManager([
                'youtube' => $youtubeProvider,
                'facebook' => FacebookTrendingProvider::class,
                'tiktok' => TikTokTrendingProvider::class,
                'instagram' => InstagramTrendingProvider::class,
                'twitter' => TwitterTrendingProvider::class,
            ]);
        });
    }
}
