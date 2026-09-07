<?php

namespace App\Providers;

use App\Services\Research\Providers\DevToResearchProvider;
use App\Services\Research\Providers\GitHubResearchProvider;
use App\Services\Research\Providers\GoogleNewsResearchProvider;
use App\Services\Research\Providers\GoogleTrendsResearchProvider;
use App\Services\Research\Providers\HackerNewsResearchProvider;
use App\Services\Research\Providers\ProductHuntResearchProvider;
use App\Services\Research\Providers\RedditResearchProvider;
use App\Services\Research\Providers\RssResearchProvider;
use App\Services\Research\Providers\StackOverflowResearchProvider;
use App\Services\Research\Providers\SteamResearchProvider;
use App\Services\Research\Providers\TmdbResearchProvider;
use App\Services\Research\Providers\TwitchResearchProvider;
use App\Services\Research\Providers\WebSearchResearchProvider;
use App\Services\Research\Providers\YouTubeResearchProvider;
use App\Services\Research\ResearchProviderManager;
use Illuminate\Support\ServiceProvider;

/**
 * The research source registry.
 *
 * Unlike TrendingServiceProvider, nothing here is env-switched: every provider is
 * always registered, and whether a source actually runs is decided per channel by
 * a database row (research_sources.enabled + the channel_research_sources pivot).
 * That is what makes sources configurable from the UI rather than from .env.
 *
 * Most providers need no credentials at all and work out of the box. The ones
 * that do (youtube, tmdb, twitch) report isConfigured() = false and are SKIPPED
 * with a visible reason instead of failing the run.
 *
 * To add a source: implement ResearchProvider, add one line to the map below, and
 * insert a research_sources row (see ResearchSourceSeeder). Nothing in the engine,
 * the scheduler or the jobs needs to change.
 */
class ResearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ResearchProviderManager::class, function () {
            return new ResearchProviderManager([
                'google_trends' => GoogleTrendsResearchProvider::class,
                'reddit' => RedditResearchProvider::class,
                'youtube' => YouTubeResearchProvider::class,
                'google_news' => GoogleNewsResearchProvider::class,
                'web_search' => WebSearchResearchProvider::class,
                'rss' => RssResearchProvider::class,
                'github' => GitHubResearchProvider::class,
                'hacker_news' => HackerNewsResearchProvider::class,
                'product_hunt' => ProductHuntResearchProvider::class,
                'devto' => DevToResearchProvider::class,
                'stackoverflow' => StackOverflowResearchProvider::class,
                'tmdb' => TmdbResearchProvider::class,
                'steam' => SteamResearchProvider::class,
                'twitch' => TwitchResearchProvider::class,
            ]);
        });
    }
}
