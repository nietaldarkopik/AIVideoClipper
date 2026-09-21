<?php

namespace App\Services\Channel;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves a user-entered YouTube channel URL/handle to a canonical channel ID
 * and its "uploads" playlist, then polls that playlist for videos published
 * after a given watermark. Uses the same free, server-side YouTube Data API v3
 * key as Services\Trending\Providers\YouTubeTrendingProvider
 * (config('services.trending.youtube_api_key')) — this is channel-lifecycle
 * logic rather than trending, so it lives in its own namespace, but there's no
 * reason to provision a second API key for it.
 */
class YouTubeChannelMonitor
{
    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    // YouTube Shorts have no dedicated flag in the Data API — duration is the
    // practical proxy. YouTube's own Shorts eligibility ceiling is 3 minutes, so
    // anything at or under that is treated as a Short and skipped: re-clipping an
    // already-short video into more shorts doesn't fit this pipeline's purpose.
    private const SHORT_MAX_SECONDS = 180;

    /**
     * @return array{channel_id: string, title: string, thumbnail_url: ?string, uploads_playlist_id: string}
     */
    public function resolveChannel(string $input): array
    {
        $input = trim($input);
        $params = $this->channelLookupParams($input);

        $item = $params
            ? $this->fetchFirstChannel($params)
            : null;

        // forUsername/forHandle/id all 404-silent (empty items[]) on a miss rather
        // than erroring, and a bare custom name (`/c/Name`) isn't guaranteed to also
        // be a valid username — search.list is the only endpoint that can resolve an
        // arbitrary display name, at 100x the quota cost, so it's kept as a last resort.
        $item ??= $this->fetchFirstChannel(['q' => $this->extractSearchTerm($input), 'type' => 'channel'], search: true);

        if (! $item) {
            throw new RuntimeException("Couldn't find a YouTube channel for \"{$input}\". Check the URL or handle and try again.");
        }

        $channelId = $item['id']['channelId'] ?? $item['id'] ?? null;
        if (! $channelId) {
            throw new RuntimeException("Couldn't find a YouTube channel for \"{$input}\". Check the URL or handle and try again.");
        }

        // search.list results don't include contentDetails, so resolve the uploads
        // playlist with a second, cheap (1-unit) channels.list?id= call when needed.
        $channel = isset($item['contentDetails']) ? $item : $this->fetchFirstChannel(['id' => $channelId]);
        if (! $channel) {
            throw new RuntimeException("Couldn't find a YouTube channel for \"{$input}\". Check the URL or handle and try again.");
        }

        $snippet = $channel['snippet'] ?? [];

        return [
            'channel_id' => $channelId,
            'title' => $snippet['title'] ?? $channelId,
            'thumbnail_url' => $snippet['thumbnails']['high']['url'] ?? $snippet['thumbnails']['default']['url'] ?? null,
            'uploads_playlist_id' => $channel['contentDetails']['relatedPlaylists']['uploads'] ?? throw new RuntimeException('Channel has no uploads playlist.'),
        ];
    }

    /**
     * $since accepts any Carbon flavor — ChannelWatch::last_video_published_at
     * comes back as the mutable Illuminate\Support\Carbon (Eloquent's default
     * datetime cast), not CarbonImmutable.
     *
     * @return array<int, array{video_id: string, title: string, published_at: CarbonImmutable, url: string}>
     */
    public function fetchNewUploads(string $uploadsPlaylistId, ?CarbonInterface $since): array
    {
        $response = Http::get(self::API_BASE.'/playlistItems', [
            'part' => 'snippet,contentDetails',
            'playlistId' => $uploadsPlaylistId,
            'maxResults' => 10,
            'key' => $this->apiKey(),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch channel uploads: '.$response->body());
        }

        $videos = collect($response->json('items', []))
            ->map(function (array $item) {
                $snippet = $item['snippet'] ?? [];

                return [
                    'video_id' => $item['contentDetails']['videoId'] ?? $snippet['resourceId']['videoId'] ?? null,
                    'title' => $snippet['title'] ?? 'Untitled',
                    // Converted to the app's timezone, not left on the API's "Z".
                    // ChannelWatch::last_video_published_at is where this ends up,
                    // and Eloquent writes a Carbon to a datetime column by
                    // formatting its wall clock verbatim — it does NOT convert to
                    // the app timezone first — while reading it back always
                    // interprets that wall clock AS the app timezone. So storing a
                    // UTC-flavoured Carbon under a non-UTC app timezone silently
                    // moves the instant (7h, for Asia/Jakarta), leaving every
                    // watermark behind its real value and making the same uploads
                    // look "new" on every single poll.
                    'published_at' => isset($snippet['publishedAt'])
                        ? CarbonImmutable::parse($snippet['publishedAt'])->setTimezone(config('app.timezone'))
                        : null,
                ];
            })
            ->filter(fn (array $v) => $v['video_id'] && $v['published_at'])
            ->when($since, fn ($videos) => $videos->filter(fn (array $v) => $v['published_at']->gt($since)))
            ->sortBy('published_at')
            ->values();

        if ($videos->isEmpty()) {
            return [];
        }

        $durations = $this->fetchDurations($videos->pluck('video_id')->all());

        $videos = $videos
            // A video missing from the durations lookup (deleted/private between
            // the two calls) can't be safely processed either way — excluded same
            // as a Short.
            ->filter(fn (array $v) => ($durations[$v['video_id']] ?? 0) > self::SHORT_MAX_SECONDS)
            ->values();

        return $videos->map(fn (array $v) => [
            ...$v,
            'url' => "https://www.youtube.com/watch?v={$v['video_id']}",
        ])->all();
    }

    /**
     * @param  array<int, string>  $videoIds
     * @return array<string, int> video_id => duration in seconds
     */
    private function fetchDurations(array $videoIds): array
    {
        $response = Http::get(self::API_BASE.'/videos', [
            'part' => 'contentDetails',
            'id' => implode(',', $videoIds),
            'key' => $this->apiKey(),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch video durations: '.$response->body());
        }

        return collect($response->json('items', []))
            ->mapWithKeys(fn (array $item) => [
                $item['id'] => $this->durationToSeconds($item['contentDetails']['duration'] ?? 'PT0S'),
            ])
            ->all();
    }

    private function durationToSeconds(string $iso8601Duration): int
    {
        try {
            $interval = new \DateInterval($iso8601Duration);
        } catch (\Exception) {
            return 0;
        }

        return $interval->d * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
    }

    private function channelLookupParams(string $input): ?array
    {
        if (preg_match('#/channel/(UC[\w-]+)#i', $input, $m) || preg_match('/^(UC[\w-]{20,})$/i', $input, $m)) {
            return ['id' => $m[1]];
        }

        if (preg_match('#(?:youtube\.com/)?@([\w.-]+)#i', $input, $m)) {
            return ['forHandle' => '@'.$m[1]];
        }

        if (preg_match('#youtube\.com/(?:c|user)/([\w.-]+)#i', $input, $m)) {
            return ['forUsername' => $m[1]];
        }

        return null;
    }

    private function extractSearchTerm(string $input): string
    {
        if (preg_match('#youtube\.com/(?:c|user)/([\w.-]+)#i', $input, $m)) {
            return $m[1];
        }

        return $input;
    }

    private function fetchFirstChannel(array $params, bool $search = false): ?array
    {
        $response = Http::get(self::API_BASE.($search ? '/search' : '/channels'), [
            ...$params,
            'part' => $search ? 'snippet' : 'snippet,contentDetails',
            'maxResults' => 1,
            'key' => $this->apiKey(),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('YouTube API request failed: '.$response->body());
        }

        return $response->json('items.0');
    }

    private function apiKey(): string
    {
        return (string) config('services.trending.youtube_api_key')
            ?: throw new RuntimeException('YOUTUBE_API_KEY is not set.');
    }
}
