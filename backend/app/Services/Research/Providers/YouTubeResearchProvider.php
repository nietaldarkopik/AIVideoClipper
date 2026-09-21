<?php

namespace App\Services\Research\Providers;

use App\Services\Research\DTOs\ResearchItem;
use App\Services\Research\DTOs\ResearchQuery;
use Carbon\CarbonImmutable;

/**
 * YouTube Data API v3. Reuses the existing YOUTUBE_API_KEY (services.trending.*
 * uses the same one) rather than introducing a second credential.
 *
 * Quota note: search.list costs 100 units against a 10,000/day default, so this
 * deliberately issues at most a couple of searches per run and then ONE batched
 * videos.list (1 unit) to fetch statistics for all of them — per-video stat calls
 * would exhaust the quota within a few channels.
 */
class YouTubeResearchProvider extends AbstractHttpResearchProvider
{
    private const BASE = 'https://www.googleapis.com/youtube/v3';

    public function key(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function type(): string
    {
        return 'video';
    }

    public function requiresCredentials(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return is_string($this->apiKey()) && $this->apiKey() !== '';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'region', 'label' => 'Region', 'type' => 'text', 'default' => 'ID'],
            ['key' => 'order', 'label' => 'Urutan', 'type' => 'select', 'options' => ['relevance', 'viewCount', 'date'], 'default' => 'viewCount'],
            ['key' => 'video_category_id', 'label' => 'ID Kategori Video', 'type' => 'text', 'help' => 'Opsional, mis. 20 untuk Gaming, 28 untuk Sains & Teknologi.'],
        ];
    }

    public function search(ResearchQuery $query): array
    {
        $region = strtoupper((string) ($query->option('region') ?: $query->region));
        $publishedAfter = CarbonImmutable::now()->subHours(max(1, $query->lookbackHours))->toIso8601ZuluString();

        $items = [];

        // Two search terms max — see the quota note on the class docblock.
        foreach ($query->primaryTerms(2) as $term) {
            $payload = $this->getJson(self::BASE.'/search', array_filter([
                'key' => $this->apiKey(),
                'part' => 'snippet',
                'q' => $term,
                'type' => 'video',
                'order' => (string) $query->option('order', 'viewCount'),
                'publishedAfter' => $publishedAfter,
                'regionCode' => $region,
                'relevanceLanguage' => $query->language,
                'videoCategoryId' => (string) $query->option('video_category_id', '') ?: null,
                'maxResults' => min(25, max(5, (int) ceil($query->limit / 2))),
            ], fn ($value) => $value !== null && $value !== ''));

            foreach ($payload['items'] ?? [] as $entry) {
                $videoId = $entry['id']['videoId'] ?? null;
                $snippet = $entry['snippet'] ?? null;

                if (! is_string($videoId) || ! is_array($snippet)) {
                    continue;
                }

                $items[$videoId] = $this->item(
                    title: $this->decode((string) ($snippet['title'] ?? '')),
                    url: 'https://www.youtube.com/watch?v='.$videoId,
                    externalId: $videoId,
                    summary: isset($snippet['description']) ? mb_substr($this->decode((string) $snippet['description']), 0, 600) : null,
                    author: $snippet['channelTitle'] ?? null,
                    publishedAt: $this->parseDate($snippet['publishedAt'] ?? null),
                    raw: ['thumbnail' => $snippet['thumbnails']['medium']['url'] ?? null],
                );
            }
        }

        return array_slice($this->attachStatistics($items), 0, $query->limit);
    }

    public function trending(ResearchQuery $query): array
    {
        $payload = $this->getJson(self::BASE.'/videos', array_filter([
            'key' => $this->apiKey(),
            'part' => 'snippet,statistics',
            'chart' => 'mostPopular',
            'regionCode' => strtoupper((string) ($query->option('region') ?: $query->region)),
            'videoCategoryId' => (string) $query->option('video_category_id', '') ?: null,
            'maxResults' => min(50, max(5, $query->limit)),
        ], fn ($value) => $value !== null && $value !== ''));

        $items = [];

        foreach ($payload['items'] ?? [] as $video) {
            $videoId = $video['id'] ?? null;
            $snippet = $video['snippet'] ?? null;

            if (! is_string($videoId) || ! is_array($snippet)) {
                continue;
            }

            $items[] = $this->item(
                title: $this->decode((string) ($snippet['title'] ?? '')),
                url: 'https://www.youtube.com/watch?v='.$videoId,
                externalId: $videoId,
                summary: isset($snippet['description']) ? mb_substr($this->decode((string) $snippet['description']), 0, 600) : null,
                author: $snippet['channelTitle'] ?? null,
                publishedAt: $this->parseDate($snippet['publishedAt'] ?? null),
                engagement: $this->statisticsToEngagement($video['statistics'] ?? []),
                raw: ['chart' => 'mostPopular'],
            );
        }

        return $items;
    }

    /**
     * One batched videos.list for every id collected, so view counts cost 1 quota
     * unit in total instead of 1 per video.
     *
     * @param  array<string, ResearchItem>  $items
     * @return ResearchItem[]
     */
    private function attachStatistics(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $ids = array_keys($items);
        $stats = [];

        // videos.list caps at 50 ids per call.
        foreach (array_chunk($ids, 50) as $chunk) {
            $payload = $this->getJson(self::BASE.'/videos', [
                'key' => $this->apiKey(),
                'part' => 'statistics',
                'id' => implode(',', $chunk),
            ]);

            foreach ($payload['items'] ?? [] as $video) {
                if (isset($video['id'])) {
                    $stats[(string) $video['id']] = $video['statistics'] ?? [];
                }
            }
        }

        $withStats = [];

        foreach ($items as $videoId => $item) {
            $withStats[] = $this->item(
                title: $item->title,
                url: $item->url,
                externalId: $item->externalId,
                summary: $item->summary,
                author: $item->author,
                publishedAt: $item->publishedAt,
                engagement: $this->statisticsToEngagement($stats[$videoId] ?? []),
                raw: $item->raw,
            );
        }

        return $withStats;
    }

    /**
     * @param  array<string, mixed>  $statistics
     * @return array<string, int>
     */
    private function statisticsToEngagement(array $statistics): array
    {
        // Counts arrive as strings, and any of them can be absent when the uploader
        // hides them — omit rather than defaulting to 0, which would read as "nobody
        // watched this" to the engagement scorer.
        return array_filter([
            'views' => isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
            'likes' => isset($statistics['likeCount']) ? (int) $statistics['likeCount'] : null,
            'comments' => isset($statistics['commentCount']) ? (int) $statistics['commentCount'] : null,
        ], fn ($value) => $value !== null);
    }

    private function apiKey(): ?string
    {
        return config('research.providers.youtube.api_key');
    }
}
