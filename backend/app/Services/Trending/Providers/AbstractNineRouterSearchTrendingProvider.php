<?php

namespace App\Services\Trending\Providers;

use App\Services\AI\Concerns\LogsAiRequests;
use App\Services\Trending\Contracts\TrendingProvider;
use App\Services\Trending\TrendingItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Approximates real trending discovery for a platform with no official trending
 * API by running a 9Router web search (/v1/search) domain-filtered to that
 * platform, instead of AbstractMockTrendingProvider's curated fake-engagement
 * seed list.
 *
 * Two honest limitations vs. a real trending API, both deliberate rather than
 * bugs:
 * - view_count/like_count/comment_count are always 0 — a web search result
 *   carries no engagement numbers, and this class does not fabricate any (unlike
 *   the mock providers, which explicitly exist to fake plausible-looking ones).
 * - source_url is best-effort, not guaranteed, to be a single yt-dlp-downloadable
 *   post — a domain_filter restricts results to the platform's own domain, which
 *   makes hitting an actual video/post page far more likely than an unfiltered
 *   search, but a hashtag/profile/landing page can still slip through. See
 *   AbstractMockTrendingProvider's docblock for why source_url correctness
 *   matters at all: TrendingCard's "Create Clip Project" button feeds it straight
 *   into yt-dlp.
 */
abstract class AbstractNineRouterSearchTrendingProvider implements TrendingProvider
{
    use LogsAiRequests;

    public function isMocked(): bool
    {
        return false;
    }

    public function fetchTrending(array $filters = []): array
    {
        $model = config('services.nine_router.web_search_model');
        if (empty($model)) {
            Log::warning('NINE_ROUTER_WEB_SEARCH_MODEL is not set, skipping web-search trending', [
                'platform' => $this->platform(),
            ]);

            return [];
        }

        $baseUrl = (string) config('services.nine_router.base_url', 'http://localhost:20128/v1');
        $span = $this->aiLogger()->start('trending_search', 'nine_router', $model, $this->searchQuery());

        try {
            $request = Http::timeout(30)
                ->retry(2, 1500)
                ->withOptions(['version' => 1.1]);

            $apiKey = config('services.nine_router.api_key');
            if ($apiKey) {
                $request = $request->withToken($apiKey);
            }

            $response = $request->post(rtrim($baseUrl, '/') . '/search', [
                'model' => $model,
                'query' => $this->searchQuery(),
                'max_results' => 10,
                'domain_filter' => $this->domainFilter(),
            ]);

            if ($response->failed()) {
                $span->failure($response->body());

                return [];
            }

            // Flat top-level object — {provider, query, results, answer, usage,
            // metrics, errors}, no "data" wrapper. (/v1/web/fetch is flat too —
            // see NineRouterWebFetchProvider.)
            $results = $response->json('results', []);
            $span->success('results: ' . count($results ?? []));
        } catch (Throwable $e) {
            $span->failure($e->getMessage());

            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $r) => $this->toTrendingItem($r),
            is_array($results) ? $results : []
        )));
    }

    private function toTrendingItem(array $result): ?TrendingItem
    {
        $url = $result['url'] ?? null;
        $title = $result['title'] ?? null;
        if (! is_string($url) || $url === '' || ! is_string($title) || $title === '') {
            return null;
        }

        $publishedAt = $result['published_at'] ?? null;

        return new TrendingItem(
            platform: $this->platform(),
            external_id: md5($url),
            title: $title,
            source_url: $url,
            thumbnail_url: $result['metadata']['image_url'] ?? null,
            author_name: $result['metadata']['author'] ?? null,
            view_count: 0,
            like_count: 0,
            comment_count: 0,
            published_at: is_string($publishedAt) ? CarbonImmutable::parse($publishedAt) : null,
            is_mock: false,
        );
    }

    /**
     * The domain(s) to restrict search results to (comma-separated for multiple),
     * e.g. "tiktok.com" — maximizes the odds a result is an actual post/video page
     * rather than an unrelated article.
     */
    abstract protected function domainFilter(): string;

    protected function searchQuery(): string
    {
        return "trending viral video on {$this->label()} today";
    }
}
