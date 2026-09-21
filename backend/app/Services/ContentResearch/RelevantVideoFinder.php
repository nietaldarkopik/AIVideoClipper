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
 * - TikTok/Instagram: web search, one call per platform with the platform name
 *   folded into the query text (e.g. "{topic} video tiktok") rather than passed
 *   as a domain_filter parameter — verified live 2026-09-03 that 9Router's
 *   /v1/search throws server-side on a single (non-combo) provider the instant
 *   ANY extra field beyond {model, query} is sent (see
 *   NineRouterWebSearchProvider's docblock), so domain_filter is unusable there
 *   regardless of which provider is registered. Mentioning the platform in the
 *   query text nudges a real search engine (confirmed working: Tavily) toward
 *   surfacing actual tiktok.com/instagram.com pages, which toCandidateVideo()
 *   below then confirms/filters by host — best-effort, not guaranteed, same
 *   spirit as AbstractNineRouterSearchTrendingProvider.
 */
class RelevantVideoFinder
{
    private const YOUTUBE_API_BASE = 'https://www.googleapis.com/youtube/v3';

    private const OTHER_PLATFORMS = ['tiktok', 'instagram'];

    /**
     * Only a path matching one of these regexes is kept as an actual video/post
     * page — an allowlist rather than a blocklist of "bad" markers. Started as a
     * blocklist (reject any path containing e.g. "/@") but that's wrong: TikTok's
     * own real video URL shape, /@username/video/1234567890, contains "/@" too —
     * a blocklist on that substring silently threw out every genuine TikTok video
     * result. Confirmed shapes: TikTok "/@user/video/<numeric id>"; Instagram
     * "/reel|reels|p|tv/<shortcode>".
     */
    private const VIDEO_PATH_PATTERNS = [
        'tiktok' => '#^/@[\w.\-]+/video/\d+#',
        'instagram' => '#^/(reel|reels|p|tv)/[\w\-]+#',
    ];

    public function __construct(private readonly WebSearchProvider $webSearch)
    {
    }

    /**
     * @return array<int, array{title: string, url: string, platform: string, thumbnail_url: ?string}>
     */
    public function find(string $topic, int $maxResults = 8): array
    {
        // YouTube search reliably returns a full page of matches for almost any
        // topic, so simply concatenating [youtube..., others...] before slicing to
        // $maxResults would crowd TikTok/Instagram out entirely every time.
        // Interleaving instead guarantees the other platforms get a fair share of
        // whatever slots $maxResults allows.
        $buckets = [$this->findYouTube($topic, $maxResults)];

        foreach (self::OTHER_PLATFORMS as $platform) {
            $webResults = $this->webSearch->search(query: "{$topic} video {$platform}", maxResults: $maxResults);
            $bucket = [];
            foreach ($webResults as $result) {
                $candidate = $this->toCandidateVideo($result);
                if ($candidate !== null) {
                    $bucket[] = $candidate;
                }
            }
            $buckets[] = $bucket;
        }

        return array_slice($this->interleave($buckets), 0, $maxResults);
    }

    /**
     * @param  array<int, array<int, array{title: string, url: string, platform: string, thumbnail_url: ?string}>>  $buckets
     * @return array<int, array{title: string, url: string, platform: string, thumbnail_url: ?string}>
     */
    private function interleave(array $buckets): array
    {
        $merged = [];
        $longest = $buckets === [] ? 0 : max(array_map('count', $buckets));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($buckets as $bucket) {
                if (isset($bucket[$i])) {
                    $merged[] = $bucket[$i];
                }
            }
        }

        return $merged;
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

        $pattern = self::VIDEO_PATH_PATTERNS[$platform] ?? null;
        $path = parse_url($result->url, PHP_URL_PATH) ?: '';
        if ($pattern !== null && ! preg_match($pattern, $path)) {
            return null;
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
