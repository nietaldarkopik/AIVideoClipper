<?php

namespace App\Services\Trending\Providers;

use App\Services\Trending\Contracts\TrendingProvider;
use App\Services\Trending\TrendingItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real trending data via the official YouTube Data API v3 (chart=mostPopular) —
 * a free, server-side-API-key endpoint, distinct from the OAuth client used
 * elsewhere in the app for real publishing (see services.google.*).
 */
class YouTubeTrendingProvider implements TrendingProvider
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    public function platform(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function isMocked(): bool
    {
        return false;
    }

    public function fetchTrending(array $filters = []): array
    {
        $response = Http::get(self::API_BASE . '/videos', [
            'chart' => 'mostPopular',
            'part' => 'snippet,statistics',
            'maxResults' => $filters['limit'] ?? 20,
            'regionCode' => $filters['region_code'] ?? config('services.trending.region_code', 'US'),
            'key' => $this->apiKey(),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch YouTube trending videos: ' . $response->body());
        }

        return array_map(function (array $item) {
            $snippet = $item['snippet'] ?? [];
            $stats = $item['statistics'] ?? [];

            return new TrendingItem(
                platform: $this->platform(),
                external_id: $item['id'],
                title: $snippet['title'] ?? 'Untitled',
                source_url: "https://www.youtube.com/watch?v={$item['id']}",
                thumbnail_url: $snippet['thumbnails']['high']['url'] ?? $snippet['thumbnails']['default']['url'] ?? null,
                author_name: $snippet['channelTitle'] ?? null,
                view_count: (int) ($stats['viewCount'] ?? 0),
                like_count: (int) ($stats['likeCount'] ?? 0),
                comment_count: (int) ($stats['commentCount'] ?? 0),
                published_at: isset($snippet['publishedAt']) ? CarbonImmutable::parse($snippet['publishedAt']) : null,
                is_mock: false,
            );
        }, $response->json('items', []));
    }

    private function apiKey(): string
    {
        return (string) config('services.trending.youtube_api_key')
            ?: throw new RuntimeException('YOUTUBE_API_KEY is not set.');
    }
}
