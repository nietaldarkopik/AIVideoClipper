<?php

namespace App\Services\ContentResearch;

use App\Services\AI\Contracts\WebSearchProvider;
use App\Services\AI\DTOs\WebSearchResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Finds candidate videos related to a topic, for use as supplementary source
 * material when composing a long-form video. Two sources, combined:
 *
 * - YouTube: the official YouTube Data API v3 search.list endpoint (same
 *   services.trending.youtube_api_key already used for real trending data) —
 *   accurate, real video pages, no dependence on any 9Router search provider.
 * - TikTok/Instagram: web search (domain-filtered), same "best-effort, not
 *   guaranteed" spirit as AbstractNineRouterSearchTrendingProvider. No free
 *   official search API exists for these, so quality depends entirely on
 *   whichever provider services.nine_router.web_search_model points at actually
 *   honoring domain_filter and returning real page URLs — many don't (e.g. a
 *   Gemini web-grounding combo returns opaque redirect URLs instead), so results
 *   here can legitimately be empty until a domain-respecting provider (tavily,
 *   brave-search, serper, a correctly-configured exa, ...) is registered.
 */
class RelevantVideoFinder
{
    private const YOUTUBE_API_BASE = 'https://www.googleapis.com/youtube/v3';

    private const DOMAIN_FILTER = 'tiktok.com,instagram.com';

    /**
     * Path segments that indicate a channel/profile/hashtag/search landing page
     * rather than a single video/post — filtered out on a best-effort basis.
     */
    private const NON_VIDEO_PATH_MARKERS = ['/channel/', '/@', '/hashtag/', '/tag/', '/search', '/c/', '/user/'];

    public function __construct(private readonly WebSearchProvider $webSearch)
    {
    }

    /**
     * @return array<int, array{title: string, url: string, platform: string, thumbnail_url: ?string}>
     */
    public function find(string $topic, int $maxResults = 8): array
    {
        $youtube = $this->findYouTube($topic, $maxResults);

        $webResults = $this->webSearch->search(
            query: "{$topic} video",
            maxResults: $maxResults,
            domainFilter: self::DOMAIN_FILTER,
        );
        $others = array_values(array_filter(array_map(
            fn (WebSearchResult $r) => $this->toCandidateVideo($r),
            $webResults
        )));

        return array_slice([...$youtube, ...$others], 0, $maxResults);
    }

    /**
     * @return array<int, array{title: string, url: string, platform: string, thumbnail_url: ?string}>
     */
    private function findYouTube(string $topic, int $maxResults): array
    {
        $apiKey = config('services.trending.youtube_api_key');
        if (empty($apiKey)) {
            return [];
        }

        $response = Http::get(self::YOUTUBE_API_BASE . '/search', [
            'part' => 'snippet',
            'q' => $topic,
            'type' => 'video',
            'maxResults' => $maxResults,
            'key' => $apiKey,
        ]);

        if ($response->failed()) {
            Log::warning('YouTube video search failed for content-brief video discovery', [
                'topic' => $topic,
                'status' => $response->status(),
            ]);

            return [];
        }

        return array_values(array_filter(array_map(function (array $item) {
            $videoId = $item['id']['videoId'] ?? null;
            $snippet = $item['snippet'] ?? [];
            if (! is_string($videoId) || $videoId === '') {
                return null;
            }

            return [
                'title' => $snippet['title'] ?? 'Untitled',
                'url' => "https://www.youtube.com/watch?v={$videoId}",
                'platform' => 'youtube',
                'thumbnail_url' => $snippet['thumbnails']['high']['url'] ?? $snippet['thumbnails']['default']['url'] ?? null,
            ];
        }, $response->json('items', []))));
    }

    /**
     * @return array{title: string, url: string, platform: string, thumbnail_url: ?string}|null
     */
    private function toCandidateVideo(WebSearchResult $result): ?array
    {
        // Some search providers (e.g. Gemini web-grounding combos) don't honor
        // domain_filter and hand back opaque redirect URLs through their own host
        // (vertexaisearch.google.com/...) instead of a real platform page — those
        // are worse than useless here: not a real video, often 300+ chars long
        // (breaks a plain varchar(255) source_url column downstream), and clicking
        // "Create Clip Project" on one just fails. Only keep a result whose host we
        // can positively identify as a known video platform.
        $host = parse_url($result->url, PHP_URL_HOST) ?: '';
        $platform = $this->platformFromHost($host);
        if ($platform === null) {
            return null;
        }

        if (mb_strlen($result->url) > 2000) {
            return null;
        }

        $path = parse_url($result->url, PHP_URL_PATH) ?: '';
        foreach (self::NON_VIDEO_PATH_MARKERS as $marker) {
            if (str_contains($path, $marker)) {
                return null;
            }
        }

        return [
            'title' => $result->title,
            'url' => $result->url,
            'platform' => $platform,
            'thumbnail_url' => $result->thumbnail_url,
        ];
    }

    private function platformFromHost(string $host): ?string
    {
        $host = strtolower($host);

        return match (true) {
            str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be') => 'youtube',
            str_contains($host, 'tiktok.com') => 'tiktok',
            str_contains($host, 'instagram.com') => 'instagram',
            default => null,
        };
    }
}
